<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Integrations\Contracts\SupportsPushSyncContract;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Enums\SyncItemStatus;
use App\Integrations\Enums\SyncRunStatus;
use App\Integrations\Enums\SyncTriggerSource;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Exceptions\SyncRunAlreadyInProgressException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationExternalMapping;
use App\Integrations\Services\IntegrationConflictService;
use App\Integrations\Services\IntegrationExternalMappingService;
use App\Integrations\Services\SyncItemService;
use App\Integrations\Services\SyncRunService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Services\WebhookRetryPolicyService;
use App\Support\TenantAwareJobContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * PushSyncJob — Checkpoint 8 (agent-8c-sync-job-design.md §1/§8-§10;
 * agent-8h-architecture-security-review.md §2 item 16). Same
 * tenant-context/connection-lock/status-gate discipline as
 * App\Jobs\PullSyncJob. $tries = 1 is deliberate, not an oversight: the
 * idempotency key below is deterministic over LOCAL STATE (connection,
 * resource identity, local_version_token), so a re-dispatch is
 * inherently safe even if queue-level retries were enabled — they stay
 * disabled so "how many times has this local-record-at-this-version
 * been attempted" has exactly one source of truth,
 * IntegrationSyncItem.attempt_count, never a second competing counter
 * on the queue side.
 */
final class PushSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJobContext;

    public int $tries = 1;

    public function __construct(
        public readonly int $firmIntegrationId,
        public readonly int $firmId,
        public readonly string $resourceType,
        public readonly string $localType,
        public readonly int $localId,
        public readonly string $localVersionToken,
        public readonly ?int $triggeringWebhookEventId = null,
        public readonly ?int $retriedRunId = null,
        // Checkpoint 12 addition (frozen-design-post-security-review.md
        // §2 F2): additive, OPTIONAL, trailing nullable param — every
        // existing caller that omits it is completely unaffected and
        // preserves today's exact behavior (provider->push() continues
        // to receive [] as its context argument). Exists so a test
        // harness (or a future real caller) can drive knobs the
        // provider's push() reads out of its $context parameter (e.g.
        // TestProvider's idempotency_key-honoring knob) without this
        // job inventing any provider-specific behavior of its own.
        //
        // Post-checkpoint-12 fix (JobConstructorsCarryOnlyScalarSecretSafeTypesTest):
        // declared ?string, not ?array — every ShouldQueue constructor
        // parameter in this codebase must be scalar/enum/DateTimeInterface
        // so Laravel never serializes an array into the queue payload.
        // Callers now pass a JSON-encoded string; decoded back to an
        // array in handle() below before use.
        public readonly ?string $providerContext = null,
    ) {}

    public function handle(
        SyncRunService $runs,
        SyncItemService $items,
        IntegrationExternalMappingService $mappings,
        IntegrationConflictService $conflicts,
        ProviderRegistry $registry,
        OutboundProviderHttpClient $httpClient,
    ): void {
        $this->runInFirmContext($this->firmId, function () use ($runs, $items, $mappings, $conflicts, $registry, $httpClient) {
            // Requirements 1-2 (tenant context restore, connection
            // validation) and requirement 3 (connection lock) — IDENTICAL
            // shape to PullSyncJob::handle().
            $connection = FirmIntegration::query()
                ->where('id', $this->firmIntegrationId)
                ->where('firm_id', $this->firmId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($connection->status !== ConnectionStatus::Active) {
                return;
            }

            $triggerSource = $this->retriedRunId !== null
                ? SyncTriggerSource::RetryPoller
                : ($this->triggeringWebhookEventId !== null ? SyncTriggerSource::Webhook : SyncTriggerSource::SchedulerPoller);

            try {
                $run = $runs->startRun(
                    $connection,
                    $this->resourceType,
                    SyncDirection::Outbound,
                    $triggerSource,
                    null,
                    $this->retriedRunId,
                    $this->triggeringWebhookEventId,
                );
            } catch (SyncRunAlreadyInProgressException $e) {
                $run = $e->existingRun;
            }

            $run = $runs->transitionStatus($run, SyncRunStatus::Running);

            $existingMapping = IntegrationExternalMapping::query()
                ->where('firm_integration_id', $connection->id)
                ->where('resource_type', $this->resourceType)
                ->where('local_type', $this->localType)
                ->where('local_id', $this->localId)
                ->whereNull('tombstoned_at')
                ->first();

            // Requirement 3 (agent-8c §8.3): reject a stale local
            // version rather than pushing anyway — the mapping's
            // last-known local_version_token disagreeing with what THIS
            // job is about to push means something else has moved the
            // local record since the mapping was last updated.
            if ($existingMapping !== null
                && $existingMapping->local_version_token !== null
                && $existingMapping->local_version_token !== $this->localVersionToken) {
                $conflicts->recordDetection(
                    $connection,
                    $this->resourceType,
                    $this->localType,
                    $this->localId,
                    'stale_local_version_push_rejected',
                    externalMappingId: $existingMapping->id,
                    localVersionToken: $this->localVersionToken,
                    externalVersionToken: $existingMapping->external_version_token,
                );

                $items->recordAttempt(
                    $connection->firm_id, $run->id, $this->resourceType, $this->localType, $this->localId,
                    $existingMapping->external_id, SyncItemStatus::Skipped,
                );

                $runs->transitionStatus($run, SyncRunStatus::PartialFailure, 'stale_local_version_push_rejected');

                return;
            }

            $provider = $registry->get(ProviderKey::from($connection->integrationProvider->code));

            if (! $provider instanceof SupportsPushSyncContract) {
                $items->recordAttempt(
                    $connection->firm_id, $run->id, $this->resourceType, $this->localType, $this->localId,
                    $existingMapping?->external_id, SyncItemStatus::FailedPermanent,
                    lastError: 'provider_does_not_support_push',
                );
                $runs->transitionStatus($run, SyncRunStatus::Failed, 'provider_does_not_support_push');

                return;
            }

            // Deterministic idempotency key (agent-8c §8.2) — over
            // (connection, resource identity, local version), so a
            // retried dispatch of the SAME local-record-at-the-same-
            // version produces the SAME key, while a legitimate
            // subsequent push of a CHANGED local record produces a NEW
            // one. Folded into the payload for a provider that honors
            // idempotency keys. Checkpoint 12 fix (post-integration gap
            // found by 12H): this is also the value TestProvider's own
            // idempotency-dedup simulation now keys off of —
            // TestProvider::push() reads $payload['idempotency_key']
            // first (falling back to $context['idempotency_key'] only
            // for a narrower test-harness-only override) precisely
            // because THIS key, always computed here for every real
            // push, is what checkpoint-00-final-specification.md §16
            // means by "TestProvider genuinely honors whatever
            // idempotency key it's given" — not a value only present
            // when a test manually injects one via providerContext.
            $idempotencyKey = hash(
                'sha256',
                "{$connection->id}:{$this->resourceType}:{$this->localType}:{$this->localId}:{$this->localVersionToken}",
            );

            $payload = [
                'local_type' => $this->localType,
                'local_id' => $this->localId,
                'idempotency_key' => $idempotencyKey,
                'existing_external_id' => $existingMapping?->external_id,
            ];

            // Checkpoint 12 fix (post-integration gap found by 12H):
            // providerContext flows into push()'s $context argument
            // below, but TestProvider::push() (and every real provider
            // contract's own convention — see agent-8c §10.2) checks
            // '__simulate_failure' against $payload, not $context. A
            // caller-supplied providerContext['__simulate_failure'] has
            // to be routed into $payload for the sentinel to actually be
            // reachable through a real job dispatch; every other
            // providerContext key (if any are ever added) keeps flowing
            // into $context unchanged, since '__simulate_failure' is the
            // only key push() reads off $payload today.
            //
            // Decoded from the JSON-encoded constructor param — see
            // this job's constructor docblock (§ post-checkpoint-12
            // fix) for why the constructor-declared type is ?string
            // rather than ?array. Only ever set by test code today
            // (frozen design), so a defensive is_array() fallback to []
            // on malformed input is sufficient.
            $providerContext = [];
            if ($this->providerContext !== null) {
                $decoded = json_decode($this->providerContext, true);
                $providerContext = is_array($decoded) ? $decoded : [];
            }

            // Checkpoint 2 (FirmsVault Live Integrations, Microsoft 365
            // provider) fix: a real provider's push() must reach
            // ProviderRequestExecutor::send(), which requires a full
            // FirmIntegration object — the pre-Checkpoint-2
            // $providerContext (test-only, JSON-encoded scalar bag)
            // never carried one. Merged in unconditionally, after
            // decoding, so any caller-supplied test keys (including
            // '__simulate_failure', handled separately below) are
            // preserved and 'connection' is always present.
            $providerContext['connection'] = $connection;

            if (array_key_exists('__simulate_failure', $providerContext)) {
                $payload['__simulate_failure'] = $providerContext['__simulate_failure'];
            }

            try {
                $result = $httpClient->execute(fn () => $provider->push($providerContext, $this->resourceType, $payload), 'push');
            } catch (SanitizedProviderHttpException $e) {
                // Checkpoint 1 (FirmsVault Live Integrations) fix
                // (checkpoint1-design-http-ratelimit-usage.md §4.4): now
                // that ProviderRequestExecutor proactively rate-limits
                // real outbound calls, a merely-rate-limited (or
                // otherwise transient) connection must not be
                // permanently failed the same way a genuinely terminal
                // failure is — that would be strictly worse than the
                // previously-unwired state. Reuses the already-existing,
                // already-tested WebhookRetryPolicyService::TERMINAL_CATEGORIES
                // list, mirroring TestResourcePushHandler's already-correct
                // identical branch — no new retry logic invented here.
                if (in_array($e->category(), WebhookRetryPolicyService::TERMINAL_CATEGORIES, true)) {
                    $items->recordAttempt(
                        $connection->firm_id, $run->id, $this->resourceType, $this->localType, $this->localId,
                        $existingMapping?->external_id, SyncItemStatus::FailedPermanent,
                        lastError: "push_failed: {$e->category()}",
                    );
                    $runs->transitionStatus($run, SyncRunStatus::Failed, "push_failed: {$e->category()}");
                } else {
                    $items->recordAttempt(
                        $connection->firm_id, $run->id, $this->resourceType, $this->localType, $this->localId,
                        $existingMapping?->external_id, SyncItemStatus::FailedRetryable,
                        lastError: "push_failed: {$e->category()}",
                        nextAttemptAt: now()->addSeconds($e->retryAfterSeconds() ?? 60)->toDateTimeString(),
                    );
                    $runs->transitionStatus($run, SyncRunStatus::PartialFailure, "push_failed: {$e->category()}");
                }

                return;
            }

            $externalId = (string) ($result['external_id'] ?? '');
            $externalVersionToken = $result['version_token'] ?? null;

            if ($existingMapping !== null) {
                $mappings->refreshVersionTokens($existingMapping, $externalVersionToken, $this->localVersionToken);
            } else {
                $mappings->recordMapping(
                    $connection,
                    $this->resourceType,
                    $this->localType,
                    $this->localId,
                    $externalId,
                    SyncDirection::Outbound,
                    $externalVersionToken,
                    $this->localVersionToken,
                );
            }

            $items->recordAttempt(
                $connection->firm_id, $run->id, $this->resourceType, $this->localType, $this->localId,
                $externalId === '' ? null : $externalId, SyncItemStatus::Succeeded,
            );

            $runs->transitionStatus($run, SyncRunStatus::Succeeded);
        });
    }
}
