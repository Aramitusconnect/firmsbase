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

    private const VALID_CATEGORIES = [
        self::CATEGORY_NETWORK_ERROR,
        self::CATEGORY_PROVIDER_REJECTED,
        self::CATEGORY_INVALID_GRANT,
        self::CATEGORY_TIMEOUT,
        self::CATEGORY_UNKNOWN,
    ];

    public function __construct(
        private readonly string $category,
        private readonly ?int $statusCode,
        string $operationLabel,
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
}
