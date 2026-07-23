<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use RuntimeException;

/**
 * SimulatedProviderFailureException — thrown ONLY by
 * App\Integrations\Providers\TestProvider\TestProvider, standing in for
 * "a real provider's outbound HTTP call failed" (a network error, a
 * rejected grant, a timeout) WITHOUT any real network call ever having
 * been made (checkpoint-00-final-specification.md §18). Carries a
 * category/statusCode pair in the same shape
 * SanitizedProviderHttpException expects, so
 * App\Integrations\Support\OutboundProviderHttpClient::execute() can
 * translate it directly.
 *
 * This exception's own $message MAY be more descriptive than what ever
 * escapes to a caller — it exists only to be caught and re-thrown as a
 * SanitizedProviderHttpException by OutboundProviderHttpClient, which
 * never forwards this message. Callers MUST always invoke TestProvider
 * failure-simulating calls through OutboundProviderHttpClient::execute(),
 * never let this exception type propagate past that boundary.
 */
class SimulatedProviderFailureException extends RuntimeException
{
    /**
     * CHECKPOINT 8 additions (agent-8e-retry-backoff-ratelimit-design.md
     * §1 / agent-8h-architecture-security-review.md §2 item 7) — the
     * same nine-category closed vocabulary
     * SanitizedProviderHttpException defines, mirrored here purely for
     * naming consistency at TestProvider call sites. This class's own
     * constructor deliberately does NOT validate $category against
     * these constants (unlike SanitizedProviderHttpException) — it
     * already accepts a free string today and existing TestProvider
     * call sites pass literal strings; OutboundProviderHttpClient::execute()
     * remains the one enforcement boundary that constructs the closed,
     * validated SanitizedProviderHttpException from whatever category
     * this exception carries.
     */
    public const CATEGORY_RATE_LIMITED = 'rate_limited';

    public const CATEGORY_AUTHENTICATION_FAILED = 'authentication_failed';

    public const CATEGORY_AUTHORIZATION_FAILED = 'authorization_failed';

    public const CATEGORY_MALFORMED_RESPONSE = 'malformed_response';

    public const CATEGORY_VALIDATION_FAILED = 'validation_failed';

    public const CATEGORY_CONFLICT = 'conflict';

    public const CATEGORY_CONFIGURATION_ERROR = 'configuration_error';

    public const CATEGORY_CONNECTION_UNAVAILABLE = 'connection_unavailable';

    public function __construct(
        private readonly string $category,
        private readonly ?int $statusCode,
        string $message,
        private readonly ?string $retryAfterRaw = null,
    ) {
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
     * The UNPARSED simulated Retry-After value, as TestProvider set it —
     * the parsing/clamping boundary lives at
     * OutboundProviderHttpClient::execute()'s translation point, not
     * here, mirroring how SanitizedProviderHttpException never carries
     * raw message/header text.
     */
    public function retryAfterRaw(): ?string
    {
        return $this->retryAfterRaw;
    }
}
