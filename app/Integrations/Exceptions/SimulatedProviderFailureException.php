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
    public function __construct(
        private readonly string $category,
        private readonly ?int $statusCode,
        string $message,
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
}
