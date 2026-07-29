<?php

declare(strict_types=1);

namespace App\Integrations\Support;

use App\Integrations\Data\ProviderHttpResponse;
use App\Integrations\Data\SanitizedHealthDiagnostic;
use App\Integrations\Data\SanitizedUsageMetadataReference;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Events\ProviderOutboundRequestCompleted;
use App\Integrations\Exceptions\ProviderEnvironmentMisconfiguredException;
use App\Integrations\Exceptions\ProviderKillSwitchActiveException;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\ProviderKillSwitch;
use App\Integrations\Services\HealthStateService;
use App\Integrations\Services\IntegrationUsageRecorderService;
use Closure;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * ProviderRequestExecutor — the shared outbound-request path every real
 * provider adapter's `push()`/`pull()`/`refreshToken()`/etc. calls
 * INTERNALLY instead of touching `Http::` itself
 * (checkpoint1-design-http-ratelimit-usage.md §1-§2;
 * checkpoint1-combined-design.md §1). This is the SOLE file anywhere
 * under `app/Integrations/` permitted to reference
 * `Illuminate\Support\Facades\Http` — `tests/Unit/Integrations/NoRealNetworkCallTest.php`
 * enforces this via a single, exact, suffix-matched exemption.
 *
 * `send()` performs five steps, in this exact order
 * (checkpoint1-combined-design.md §1):
 *
 *   1. Environment/URL guard —
 *      `ProviderEnvironmentResolver::assertUrlAllowedFor()`, using the
 *      security-review-corrected `parse_url()`-based structured
 *      comparison (checkpoint1-security-review.md Finding 1) — runs
 *      FIRST, before the rate limiter, so a misconfigured URL is
 *      rejected without consuming any of the connection's rate-limit
 *      budget. Throws `ProviderEnvironmentMisconfiguredException`,
 *      UNCAUGHT by this method — a configuration bug, never mapped into
 *      SanitizedProviderHttpException's retryable/terminal vocabulary.
 *   2. Rate-limit gate — `PerConnectionRateLimiter::attempt()`. On
 *      rejection: NO `Http::` call is ever made, `HealthStateService::recordRateLimited()`
 *      is called, and a sanitized `rate_limited` exception is thrown. NO
 *      usage row is written for a rejected attempt (checkpoint1-design-http-ratelimit-usage.md
 *      §2.7 — a proactively-blocked request consumed zero provider-side
 *      quota).
 *   3. Build + send the real `Http::` request, with closure-based auth
 *      injection (mirrors the existing
 *      `OutboundProviderHttpClient::execute(Closure ...)` /
 *      `IntegrationOAuthStateService::initiate(..., Closure ...)`
 *      pattern), a correlation-id header, a default idempotency header,
 *      and a timeout — timed regardless of outcome.
 *   4. Classify the outcome into one of `SanitizedProviderHttpException`'s
 *      closed categories and record usage via
 *      `IntegrationUsageRecorderService::recordOnce()` (success AND
 *      failure, never for the proactive rate-limit rejection) with ONLY
 *      four allowlisted metadata fields (`status_code`, `category`,
 *      `duration_ms`, `http_method`) — never request/response body
 *      content. This is verified BY CONSTRUCTION: this class has no code
 *      path that copies request/response body/header content into the
 *      usage metadata array, and `send()`'s own signature carries no
 *      caller-suppliable "extra metadata" escape hatch that could
 *      reintroduce one.
 *   5. Record health via the matching `HealthStateService::record*()`
 *      method, using the FIXED category-mapping table
 *      (checkpoint1-security-review.md Finding 4):
 *      `authentication_failed`/`invalid_grant` -> `credential_error`;
 *      `authorization_failed` -> `scope_error`; `rate_limited` ->
 *      `rate_limited`; every other category -> `provider_error`. This
 *      table is pinned HERE, once, for every future caller — it must
 *      never be re-derived independently by a future provider adapter.
 *
 * REQUIRED (checkpoint1-security-review.md Finding 7): this method must
 * NEVER call Laravel's `PendingRequest::retry()`. All retry/backoff
 * logic belongs at the job/outbox layer (`PushSyncJob`, `PullSyncJob`,
 * `SyncRetryPollJob`, `IntegrationOutboxEventService::fail()`), which
 * already handles it correctly via `SanitizedProviderHttpException::category()` +
 * `WebhookRetryPolicyService::TERMINAL_CATEGORIES`. A `retry()` call
 * here would risk a `when` callback receiving the full, auth-injected
 * `$request` object (bearer token included) and logging it unsanitized —
 * completely bypassing every sanitization boundary this domain
 * otherwise enforces. A raw caught exception's `getMessage()` is read
 * ONLY internally, to distinguish a connection-timeout from a generic
 * connection failure — its value is NEVER included in the thrown
 * `SanitizedProviderHttpException`, a log line, or any recorded
 * metadata.
 *
 * `operationType` is deliberately restricted to the four values this
 * checkpoint's own `HealthStateService`/`SanitizedHealthDiagnostic`
 * operation-label vocabulary can actually represent
 * (`push`/`pull`/`refresh_token`/`health_check`) — see
 * `operationLabelFor()`. `webhook_subscribe`/`disconnect`, named in the
 * original design draft's broader signature, have no matching
 * `SanitizedHealthDiagnostic::OPERATION_*` label today; extending this
 * list is deferred to whichever future checkpoint first needs it,
 * coordinated with the owner of `SanitizedHealthDiagnostic` (a file
 * this checkpoint's file allowlist does not include).
 *
 * FirmsVault Live Integrations, Checkpoint 2 additions
 * (checkpoint2-combined-design.md §2 P-7): the deferral above is now
 * resolved for `token_exchange` — every real OAuth2 provider's initial
 * authorization-code exchange needs its own operation-type/label, and
 * `SanitizedHealthDiagnostic::OPERATION_TOKEN_EXCHANGE` now exists (see
 * that class). `webhook_subscribe` is added at the same time (a Graph
 * subscription-establishment call has the identical need). Both are
 * appended to `SUPPORTED_OPERATION_TYPES` and `operationLabelFor()`'s
 * match, without touching the four original values' behavior.
 *
 *   (a) `send()` gains an optional trailing `bool $formEncoded = false`
 *       parameter (checkpoint2-security-review.md Finding 3, confirmed
 *       safe): when true, the outbound request body is built as
 *       `['form_params' => $body]` instead of `['json' => $body]` —
 *       required by every real OAuth2 token endpoint per RFC 6749
 *       §4.1.3, which rejects a JSON-encoded body outright. Defaults
 *       false, so every existing call site's behavior is byte-for-byte
 *       unchanged.
 *   (b) `send()` gains an optional trailing `string $urlPurpose = 'default'`
 *       parameter, threaded through to
 *       `ProviderEnvironmentResolver::assertUrlAllowedFor()` — lets a
 *       dual-host provider (e.g. Microsoft's identity host vs. its
 *       Graph resource-API host) validate each outbound call against
 *       the correct configured host (see `ProviderEnvironmentResolver`'s
 *       own Checkpoint 2 widening).
 *   (c) `categorizeStatus()` gains a `410` arm mapping to
 *       `SanitizedProviderHttpException::CATEGORY_CURSOR_EXPIRED`.
 */
final class ProviderRequestExecutor
{
    private const SUPPORTED_OPERATION_TYPES = ['push', 'pull', 'refresh_token', 'health_check', 'token_exchange', 'webhook_subscribe'];

    /**
     * Response headers copied into the returned ProviderHttpResponse —
     * an explicit allowlist only, never a blind passthrough (checkpoint1-design-http-ratelimit-usage.md
     * §2.3).
     */
    private const ALLOWLISTED_RESPONSE_HEADERS = ['Retry-After', 'Content-Type'];

    public function __construct(
        private readonly PerConnectionRateLimiter $rateLimiter,
        private readonly IntegrationUsageRecorderService $usageRecorder,
        private readonly HealthStateService $healthState,
        private readonly ProviderEnvironmentResolver $environmentResolver,
    ) {}

    /**
     * @param  Closure(PendingRequest): PendingRequest  $authInjector
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     *
     * @throws SanitizedProviderHttpException on any failure (rate-limit rejection, network error, non-2xx response)
     * @throws ProviderEnvironmentMisconfiguredException on a config/URL-guard failure
     * @throws ProviderKillSwitchActiveException on a platform-admin-authored LEVEL_PROVIDER kill switch
     */
    public function send(
        FirmIntegration $connection,
        ProviderKey $providerKey,
        string $method,
        string $url,
        string $capability,
        string $operationType,
        SyncDirection $direction,
        ?ResourceType $resourceType,
        Closure $authInjector,
        string $usageIdempotencyKey,
        array $body = [],
        array $headers = [],
        ?string $correlationId = null,
        ?int $timeoutSeconds = null,
        int $usageQuantity = 1,
        bool $formEncoded = false,
        string $urlPurpose = 'default',
    ): ProviderHttpResponse {
        if (! in_array($operationType, self::SUPPORTED_OPERATION_TYPES, true)) {
            throw new InvalidArgumentException(
                "ProviderRequestExecutor::send() received an unsupported operationType \"{$operationType}\"."
            );
        }

        $correlationId ??= (string) Str::uuid7();

        // STEP 0 — provider-level kill switch. Checkpoint 6 addition
        // (cross-provider security/ops review): before this, the ONLY
        // code that ever checked `provider_kill_switches` was
        // ProviderOperationPolicyResolver, reached only via
        // ProviderBillableCallPipeline — which only Plaid routes
        // through. Microsoft 365 and Google Workspace had no
        // admin-triggerable emergency disable at all. This check runs
        // here, in the ONE outbound path every provider adapter shares,
        // so a LEVEL_PROVIDER switch stops calls for any provider
        // uniformly. Runs first, before the environment/URL guard and
        // the rate limiter, so a killed provider never consumes budget
        // or attempts a call. Deliberately uncaught here — mirrors
        // ProviderBillableCallPipeline's own "no further step runs
        // after this throws" contract for the identical exception type;
        // Plaid's existing product/endpoint-category/operation-level
        // switches are untouched and still checked separately by the
        // billing pipeline (this is an additional, coarser gate, not a
        // replacement).
        $providerKilled = ProviderKillSwitch::query()
            ->where('provider_key', $providerKey->value)
            ->where('scope_type', ProviderKillSwitch::SCOPE_PLATFORM)
            ->whereNull('scope_id')
            ->where('level', ProviderKillSwitch::LEVEL_PROVIDER)
            ->where('target', $providerKey->value)
            ->where('suspended', true)
            ->first();

        if ($providerKilled !== null) {
            throw new ProviderKillSwitchActiveException(
                $providerKey->value,
                ProviderKillSwitch::LEVEL_PROVIDER,
                $providerKey->value,
                $providerKilled->reason,
            );
        }

        // STEP 1 — environment/URL guard. Runs first, before the rate
        // limiter, so a misconfigured URL never consumes rate-limit
        // budget. Deliberately uncaught here. $urlPurpose lets a
        // dual-host provider validate this specific call against the
        // correct configured host (Checkpoint 2 addition).
        $this->environmentResolver->assertUrlAllowedFor($providerKey, $url, $urlPurpose);

        // STEP 2 — proactive rate-limit gate. No HTTP call is made on
        // rejection, and no usage row is written for it.
        $budget = $this->resolveRateLimitBudget($providerKey);

        $allowed = $this->rateLimiter->attempt(
            $connection->id,
            $budget['max_attempts_per_window'],
            $budget['window_seconds'],
        );

        if (! $allowed) {
            $retryAfterSeconds = $this->rateLimiter->availableIn($connection->id);

            $this->healthState->recordRateLimited(
                $connection->id,
                $connection->firm_id,
                now()->addSeconds($retryAfterSeconds),
                new SanitizedHealthDiagnostic(
                    SanitizedHealthDiagnostic::CATEGORY_RATE_LIMITED,
                    $this->operationLabelFor($operationType),
                ),
            );

            throw new SanitizedProviderHttpException(
                SanitizedProviderHttpException::CATEGORY_RATE_LIMITED,
                null,
                $operationType,
                $retryAfterSeconds,
                $correlationId,
            );
        }

        // STEP 3 — build + send the real HTTP request, timed regardless
        // of outcome.
        $httpMethod = strtoupper($method);
        $timeout = $timeoutSeconds ?? (int) config('integrations.http.default_timeout_seconds', 15);
        $connectTimeout = (int) config('integrations.http.connect_timeout_seconds', 5);
        $correlationHeaderName = (string) config('integrations.http.correlation_id_header', 'X-FirmsVault-Correlation-Id');

        $request = Http::timeout($timeout)
            ->connectTimeout($connectTimeout)
            ->withHeaders($headers)
            ->withHeaders([$correlationHeaderName => $correlationId]);

        if (! $this->hasIdempotencyHeader($headers)) {
            $request = $request->withHeaders(['Idempotency-Key' => $usageIdempotencyKey]);
        }

        $request = $authInjector($request);

        // Checkpoint 2 addition: $formEncoded selects 'form_params'
        // (application/x-www-form-urlencoded) over 'json' for a non-GET
        // call — required by every real OAuth2 token endpoint (RFC 6749
        // §4.1.3). Confirmed safe (checkpoint2-security-review.md
        // Finding 3): this is the ONLY place $body influences the
        // outbound request; no other step in this method (usage
        // metadata, exception construction, health recording) ever
        // reads $body, so this branch cannot introduce a new leak
        // surface for either encoding.
        //
        // GAP FOUND AND FIXED (checkpoint2-diff-review.md, "production
        // bug found" during Checkpoint 2 test-writing): the GET branch
        // used to unconditionally pass ['query' => $body] to Guzzle —
        // but Guzzle's `query` request option, once set to ANY value
        // (including an empty array), REPLACES the target URL's
        // existing query string wholesale rather than merging with it.
        // Microsoft's delta-query pagination (Microsoft365Provider::pull())
        // passes a full, pre-built, opaque `@odata.nextLink`/
        // `@odata.deltaLink` URL — already carrying its own
        // `$skiptoken`/`$deltatoken` query string — as $url, with an
        // EMPTY $body (it has no separate params to merge in). Passing
        // `['query' => []]` for that call silently stripped the token
        // out of the URL before the request was ever sent, defeating
        // pagination/incremental-sync entirely (confirmed empirically:
        // every page after the first, and every subsequent incremental
        // sync reusing a stored deltaLink, would have silently re-hit
        // the bare, token-less base URL). Fix: only set the `query`
        // option when $body is genuinely non-empty — an empty $body on
        // a GET means "the caller's URL already carries everything it
        // needs," never "explicitly request zero query params."
        $options = match (true) {
            $httpMethod === 'GET' && $body !== [] => ['query' => $body],
            $httpMethod === 'GET' => [],
            $formEncoded => ['form_params' => $body],
            default => ['json' => $body],
        };

        $startedAt = microtime(true);

        try {
            $response = $request->send($httpMethod, $url, $options);
        } catch (ConnectionException $e) {
            $durationMs = $this->elapsedMs($startedAt);
            // Read internally ONLY to distinguish timeout from a generic
            // connection failure — never forwarded to any exception,
            // log, or recorded metadata (Finding 7).
            $category = $this->looksLikeTimeout($e->getMessage())
                ? SanitizedProviderHttpException::CATEGORY_TIMEOUT
                : SanitizedProviderHttpException::CATEGORY_NETWORK_ERROR;

            $this->recordUsage(
                $connection, $providerKey, $capability, $operationType, $direction, $resourceType,
                'failure', $usageIdempotencyKey, $correlationId, null, $category, $durationMs, $httpMethod, $usageQuantity,
            );

            $this->recordHealthFailure($connection, $category, $operationType, null, $durationMs);

            throw new SanitizedProviderHttpException($category, null, $operationType, null, $correlationId);
        }

        $durationMs = $this->elapsedMs($startedAt);
        $statusCode = $response->status();
        $isSuccessStatus = $statusCode >= 200 && $statusCode < 300;
        $decoded = $this->tryDecodeJson($response->body());

        if ($isSuccessStatus && $decoded !== null) {
            $this->recordUsage(
                $connection, $providerKey, $capability, $operationType, $direction, $resourceType,
                'success', $usageIdempotencyKey, $correlationId, $statusCode, null, $durationMs, $httpMethod, $usageQuantity,
            );

            $this->healthState->recordSuccess($connection->id, $connection->firm_id, $durationMs);

            return new ProviderHttpResponse($statusCode, $decoded, $this->allowlistedHeaders($response));
        }

        $category = $isSuccessStatus
            ? SanitizedProviderHttpException::CATEGORY_MALFORMED_RESPONSE
            : $this->categorizeStatus($statusCode);

        $retryAfterSeconds = $this->parseRetryAfter($response);

        $this->recordUsage(
            $connection, $providerKey, $capability, $operationType, $direction, $resourceType,
            'failure', $usageIdempotencyKey, $correlationId, $statusCode, $category, $durationMs, $httpMethod, $usageQuantity,
        );

        $this->recordHealthFailure($connection, $category, $operationType, $retryAfterSeconds, $durationMs);

        throw new SanitizedProviderHttpException($category, $statusCode, $operationType, $retryAfterSeconds, $correlationId);
    }

    /**
     * @return array{max_attempts_per_window: int, window_seconds: int}
     */
    private function resolveRateLimitBudget(ProviderKey $providerKey): array
    {
        $budget = config("integrations.rate_limits.providers.{$providerKey->value}")
            ?? config('integrations.rate_limits.default')
            ?? ['max_attempts_per_window' => 30, 'window_seconds' => 60];

        return [
            'max_attempts_per_window' => (int) ($budget['max_attempts_per_window'] ?? 30),
            'window_seconds' => (int) ($budget['window_seconds'] ?? 60),
        ];
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private function hasIdempotencyHeader(array $headers): bool
    {
        foreach (array_keys($headers) as $key) {
            if (strtolower((string) $key) === 'idempotency-key') {
                return true;
            }
        }

        return false;
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function looksLikeTimeout(string $message): bool
    {
        $normalized = strtolower($message);

        return str_contains($normalized, 'timed out') || str_contains($normalized, 'timeout');
    }

    /**
     * @return array<string, mixed>|null null signals the body could not
     *                                   be parsed as a JSON object/array
     *                                   (CATEGORY_MALFORMED_RESPONSE on
     *                                   an otherwise-2xx response). An
     *                                   empty body is treated as an
     *                                   empty payload, not malformed.
     */
    private function tryDecodeJson(string $body): ?array
    {
        if (trim($body) === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * @return array<string, string>
     */
    private function allowlistedHeaders(Response $response): array
    {
        $headers = [];

        foreach (self::ALLOWLISTED_RESPONSE_HEADERS as $name) {
            $value = $response->header($name);

            if ($value !== null && $value !== '') {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    private function parseRetryAfter(Response $response): ?int
    {
        $raw = $response->header('Retry-After');

        if ($raw === null || $raw === '') {
            return null;
        }

        return (new RetryAfterParser((int) config('integrations.outbox.max_backoff_seconds', 3600)))
            ->parse($raw, new DateTimeImmutable);
    }

    /**
     * Closed status -> category table (checkpoint1-design-http-ratelimit-usage.md
     * §2.4 step 7) — no new categories invented beyond
     * SanitizedProviderHttpException's own existing closed vocabulary.
     */
    private function categorizeStatus(int $status): string
    {
        return match (true) {
            $status === 401 => SanitizedProviderHttpException::CATEGORY_AUTHENTICATION_FAILED,
            $status === 403 => SanitizedProviderHttpException::CATEGORY_AUTHORIZATION_FAILED,
            $status === 400, $status === 422 => SanitizedProviderHttpException::CATEGORY_VALIDATION_FAILED,
            $status === 404 => SanitizedProviderHttpException::CATEGORY_PROVIDER_REJECTED,
            $status === 409 => SanitizedProviderHttpException::CATEGORY_CONFLICT,
            // Checkpoint 2 addition (checkpoint2-combined-design.md §2
            // P-7c): a 410 Gone response (e.g. Microsoft Graph's
            // delta-query cursor-expiry signal) is a distinct failure
            // shape from a generic 404 — see
            // SanitizedProviderHttpException::CATEGORY_CURSOR_EXPIRED's
            // own docblock for why this is its own category rather than
            // folded into CATEGORY_PROVIDER_REJECTED.
            $status === 410 => SanitizedProviderHttpException::CATEGORY_CURSOR_EXPIRED,
            $status === 429 => SanitizedProviderHttpException::CATEGORY_RATE_LIMITED,
            $status >= 500 && $status <= 599 => SanitizedProviderHttpException::CATEGORY_PROVIDER_REJECTED,
            default => SanitizedProviderHttpException::CATEGORY_UNKNOWN,
        };
    }

    private function recordUsage(
        FirmIntegration $connection,
        ProviderKey $providerKey,
        string $capability,
        string $operationType,
        SyncDirection $direction,
        ?ResourceType $resourceType,
        string $outcome,
        string $usageIdempotencyKey,
        string $correlationId,
        ?int $statusCode,
        ?string $category,
        int $durationMs,
        string $httpMethod,
        int $usageQuantity,
    ): void {
        // ONLY these four fixed, pre-approved scalar fields — never
        // $body, $headers, or the raw response payload (checkpoint1-design-http-ratelimit-usage.md
        // §2.7).
        $this->usageRecorder->recordOnce(
            firmId: $connection->firm_id,
            firmIntegrationId: $connection->id,
            providerKey: $providerKey->value,
            capability: $capability,
            operationType: $operationType,
            direction: $direction,
            resourceType: $resourceType,
            unit: 'request',
            outcome: $outcome,
            idempotencyKey: $usageIdempotencyKey,
            quantity: $usageQuantity,
            metadata: new SanitizedUsageMetadataReference([
                'status_code' => $statusCode,
                'category' => $category,
                'duration_ms' => $durationMs,
                'http_method' => $httpMethod,
            ]),
            correlationId: $correlationId,
        );

        ProviderOutboundRequestCompleted::dispatch(
            $providerKey->value,
            $operationType,
            $outcome,
            $category,
            $statusCode,
            $durationMs,
            $correlationId,
            $connection->id,
        );
    }

    /**
     * FIXED category-mapping table (checkpoint1-security-review.md
     * Finding 4) — must not be re-derived independently by any future
     * caller.
     */
    private function recordHealthFailure(
        FirmIntegration $connection,
        string $category,
        string $operationType,
        ?int $retryAfterSeconds,
        ?int $latencyMs = null,
    ): void {
        $healthCategory = $this->mapToHealthCategory($category);
        $diagnostic = new SanitizedHealthDiagnostic($healthCategory, $this->operationLabelFor($operationType));

        match ($healthCategory) {
            SanitizedHealthDiagnostic::CATEGORY_RATE_LIMITED => $this->healthState->recordRateLimited(
                $connection->id,
                $connection->firm_id,
                now()->addSeconds($retryAfterSeconds ?? (int) config('integrations.health.backoff_base_seconds', 60)),
                $diagnostic,
                $latencyMs,
            ),
            SanitizedHealthDiagnostic::CATEGORY_CREDENTIAL_ERROR => $this->healthState->recordCredentialError(
                $connection->id,
                $connection->firm_id,
                $diagnostic,
                $latencyMs,
            ),
            SanitizedHealthDiagnostic::CATEGORY_SCOPE_ERROR => $this->healthState->recordScopeError(
                $connection->id,
                $connection->firm_id,
                $diagnostic,
                $latencyMs,
            ),
            default => $this->healthState->recordProviderError(
                $connection->id,
                $connection->firm_id,
                $diagnostic,
                $latencyMs,
            ),
        };
    }

    /**
     * FIXED category-mapping table (checkpoint1-security-review.md
     * Finding 4) — must not be re-derived independently by any future
     * caller.
     *
     * Checkpoint 2 note (checkpoint2-combined-design.md §2 P-11):
     * CATEGORY_CURSOR_EXPIRED intentionally falls through to the
     * `default` arm below (=> CATEGORY_PROVIDER_ERROR) — this is
     * deliberate, not an oversight. From the health dashboard's
     * perspective a cursor expiry IS a form of provider error (nothing
     * about the connection's credentials or granted scopes is wrong);
     * the dedicated `CATEGORY_CURSOR_EXPIRED` distinction exists for the
     * SYNC layer's own self-healing branch (invalidate-and-restart),
     * not for a separate health-signal category. Adding a fifth
     * `SanitizedHealthDiagnostic::CATEGORY_*` value here would widen
     * `HealthStateService`'s own closed `last_failure_category` column
     * vocabulary for no operational benefit.
     */
    private function mapToHealthCategory(string $sanitizedCategory): string
    {
        return match ($sanitizedCategory) {
            SanitizedProviderHttpException::CATEGORY_AUTHENTICATION_FAILED,
            SanitizedProviderHttpException::CATEGORY_INVALID_GRANT => SanitizedHealthDiagnostic::CATEGORY_CREDENTIAL_ERROR,
            SanitizedProviderHttpException::CATEGORY_AUTHORIZATION_FAILED => SanitizedHealthDiagnostic::CATEGORY_SCOPE_ERROR,
            SanitizedProviderHttpException::CATEGORY_RATE_LIMITED => SanitizedHealthDiagnostic::CATEGORY_RATE_LIMITED,
            // CATEGORY_CURSOR_EXPIRED falls through to here — see this
            // method's own docblock above.
            default => SanitizedHealthDiagnostic::CATEGORY_PROVIDER_ERROR,
        };
    }

    private function operationLabelFor(string $operationType): string
    {
        return match ($operationType) {
            'push' => SanitizedHealthDiagnostic::OPERATION_PUSH_SYNC,
            'pull' => SanitizedHealthDiagnostic::OPERATION_PULL_SYNC,
            'refresh_token' => SanitizedHealthDiagnostic::OPERATION_TOKEN_REFRESH,
            'health_check' => SanitizedHealthDiagnostic::OPERATION_HEALTH_CHECK,
            // Checkpoint 2 additions (checkpoint2-combined-design.md §2
            // P-7b).
            'token_exchange' => SanitizedHealthDiagnostic::OPERATION_TOKEN_EXCHANGE,
            'webhook_subscribe' => SanitizedHealthDiagnostic::OPERATION_WEBHOOK_SUBSCRIBE,
            default => throw new InvalidArgumentException(
                "ProviderRequestExecutor has no health-diagnostic operation label mapping for operationType \"{$operationType}\"."
            ),
        };
    }
}
