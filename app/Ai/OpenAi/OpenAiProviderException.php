<?php

declare(strict_types=1);

namespace App\Ai\OpenAi;

use RuntimeException;

/**
 * Carries a classified, secret-free failure reason.
 *
 * The message is taken from the enum, never from the provider body, so an
 * exception that reaches a log or an error page cannot contain credential
 * material or a reflected request.
 */
final class OpenAiProviderException extends RuntimeException
{
    public function __construct(
        public readonly OpenAiFailureReason $reason,
        public readonly ?int $status = null,
    ) {
        parent::__construct($reason->message());
    }
}
