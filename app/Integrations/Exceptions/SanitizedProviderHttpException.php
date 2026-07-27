<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use InvalidArgumentException;
use RuntimeException;

/**
 * SanitizedProviderHttpException — the ONLY shape an outbound-provider-
 * call failure may take once it leaves App\Integrations\Support\OutboundProviderHttpClient
 * or App\Integrations\Support\ProviderRequestExecutor (agent-h-security-architecture-review.md
 * item 19; checkpoint1-design-http-ratelimit-usage.md §2.9 —
 * ProviderRequestExecutor is a second authorized construction site,
 * added by the FirmsVault Live Integrations mission's Checkpoint 1).
 * Carries ONLY a
 * small, closed category string and an optional HTTP status code —
 * NEVER the original exception's message, headers, or response body,
 * which may embed request/response detail (tokens, secrets, internal
 * hostnames) that must never reach a logger, a TimelineEventRecorder
 * metadata array, or a failed_jobs row.
 *
 * The category set is intentionally small and closed (validated in the
 * constructor) rather than an open string, so callers can safely branch
 * on it (e.g. CATEGORY_INVALID_GRANT -> transition the connection to
 * ReauthorizationRequired) without ever needing to inspect free-text.
 */
final class SanitizedProviderHttpException extends RuntimeException
{
    public const CATEGORY_NETWORK_ERROR = 'network_error';

    public const CATEGORY_PROVIDER_REJECTED = 'provider_rejected';

    public const CATEGORY_INVALID_GRANT = 'invalid_grant';

    public const CATEGORY_TIMEOUT = 'timeout';

    public const CATEGORY_UNKNOWN = 'unknown';

    /**
     * CHECKPOINT 8 additions (agent-8e-retry-backoff-ratelimit-design.md
     * §1 — the nine-category closed retry/dead-letter taxonomy;
     * agent-8h-architecture-security-review.md §2 item 7). Every new
     * category below is closed/validated exactly like the five original
     * ones — never a free string.
     */
    public const CATEGORY_RATE_LIMITED = 'rate_limited';

    public const CATEGORY_AUTHENTICATION_FAILED = 'authentication_failed';

    public const CATEGORY_AUTHORIZATION_FAILED = 'authorization_failed';

    public const CATEGORY_MALFORMED_RESPONSE = 'malformed_response';

    public const CATEGORY_VALIDATION_FAILED = 'validation_failed';

    public const CATEGORY_CONFLICT = 'conflict';

    public const CATEGORY_CONFIGURATION_ERROR = 'configuration_error';

    public const CATEGORY_CONNECTION_UNAVAILABLE = 'connection_unavailable';

    /**
     * FirmsVault Live Integrations, Checkpoint 2 addition
     * (checkpoint2-combined-design.md §2 P-9): a real provider's
     * incremental-sync cursor can expire server-side (e.g. Microsoft
     * Graph delta-query's `410 Gone` response), which is a distinct
     * failure shape from every other category above — the fix is not
     * "retry the same request" or "reauthorize", it is "invalidate the
     * stored cursor and restart the walk from scratch". Kept as its own
     * closed category (never folded into CATEGORY_PROVIDER_REJECTED)
     * specifically so a caller (e.g. a future PullSyncJob catch branch)
     * can branch on it directly rather than re-deriving "was this a 410"
     * from a raw status code it may not even have in scope.
     */
    public const CATEGORY_CURSOR_EXPIRED = 'cursor_expired';

    private const VALID_CATEGORIES = [
        self::CATEGORY_NETWORK_ERROR,
        self::CATEGORY_PROVIDER_REJECTED,
        self::CATEGORY_INVALID_GRANT,
        self::CATEGORY_TIMEOUT,
        self::CATEGORY_UNKNOWN,
        self::CATEGORY_RATE_LIMITED,
        self::CATEGORY_AUTHENTICATION_FAILED,
        self::CATEGORY_AUTHORIZATION_FAILED,
        self::CATEGORY_MALFORMED_RESPONSE,
        self::CATEGORY_VALIDATION_FAILED,
        self::CATEGORY_CONFLICT,
        self::CATEGORY_CONFIGURATION_ERROR,
        self::CATEGORY_CONNECTION_UNAVAILABLE,
        self::CATEGORY_CURSOR_EXPIRED,
    ];

    public function __construct(
        private readonly string $category,
        private readonly ?int $statusCode,
        string $operationLabel,
        private readonly ?int $retryAfterSeconds = null,
        // Checkpoint 1 (FirmsVault Live Integrations) addition
        // (checkpoint1-design-http-ratelimit-usage.md §2.9): additive,
        // optional, trailing param — every existing call site (both in
        // OutboundProviderHttpClient::execute()) is unaffected. Never a
        // secret: a synthetic UUID minted by this system, never derived
        // from or containing provider response content, so carrying it
        // on the exception lets a job's catch block fold it into a
        // TimelineEventRecorder metadata array or a last_error string
        // for tracing without ever risking a real secret leak.
        private readonly ?string $correlationId = null,
    ) {
        if (! in_array($category, self::VALID_CATEGORIES, true)) {
            throw new InvalidArgumentException("Unknown provider-error category: \"{$category}\".");
        }

        $message = "Outbound provider call [{$operationLabel}] failed: category={$category}";

        if ($statusCode !== null) {
            $message .= ", status={$statusCode}";
        }

        parent::__construct($message);
    }

    public function category(): string
    {
        return $this->category;
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * The already-parsed-and-clamped Retry-After delay, in seconds — set
     * ONLY by OutboundProviderHttpClient::execute()'s translation of a
     * SimulatedProviderFailureException's raw retryAfterRaw() value
     * through App\Integrations\Support\RetryAfterParser. Never the raw
     * string — preserves this class's existing "never the original ...
     * headers" discipline exactly.
     */
    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    public function correlationId(): ?string
    {
        return $this->correlationId;
    }
}
