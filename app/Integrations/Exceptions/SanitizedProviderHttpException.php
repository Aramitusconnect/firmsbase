<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use InvalidArgumentException;
use RuntimeException;

/**
 * SanitizedProviderHttpException — the ONLY shape an outbound-provider-
 * call failure may take once it leaves App\Integrations\Support\OutboundProviderHttpClient
 * (agent-h-security-architecture-review.md item 19). Carries ONLY a
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
    ];

    public function __construct(
        private readonly string $category,
        private readonly ?int $statusCode,
        string $operationLabel,
        private readonly ?int $retryAfterSeconds = null,
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
}
