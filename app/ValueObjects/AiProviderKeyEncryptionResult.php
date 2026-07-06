<?php

namespace App\ValueObjects;

/**
 * AiProviderKeyEncryptionResult — mirrors EmailBodyEncryptionResult's
 * exact fail-closed shape. AiProviderKeyService MUST NOT write a
 * firm_ai_provider_keys row when succeeded is false.
 */
final readonly class AiProviderKeyEncryptionResult
{
    private function __construct(
        public bool $succeeded,
        public ?string $ciphertext = null,
        public ?int $encryptionKeyId = null,
        public ?string $reason = null,
    ) {
    }

    public static function success(string $ciphertext, int $encryptionKeyId): self
    {
        return new self(true, $ciphertext, $encryptionKeyId);
    }

    public static function failure(string $reason): self
    {
        return new self(false, reason: $reason);
    }
}
