<?php

declare(strict_types=1);

namespace App\ValueObjects;

/**
 * The outcome of "Test Connection" on the firm's AI settings page.
 *
 * $code is machine-readable and stable (tests and logs match on it); $message
 * is the firm-facing sentence. Both are drawn from a closed vocabulary — never
 * from a provider response body — so no credential fragment, reflected request
 * or upstream trace can reach a screen or a log through this object.
 */
final readonly class AiProviderConnectionTestResult
{
    private function __construct(
        public bool $succeeded,
        public string $code,
        public string $message,
        public ?string $model = null,
        public ?int $status = null,
    ) {}

    public static function success(string $model): self
    {
        return new self(
            succeeded: true,
            code: 'ok',
            message: "OpenAI accepted the credential and responded using {$model}.",
            model: $model,
        );
    }

    public static function failure(string $code, string $message, ?string $model = null, ?int $status = null): self
    {
        return new self(
            succeeded: false,
            code: $code,
            message: $message,
            model: $model,
            status: $status,
        );
    }
}
