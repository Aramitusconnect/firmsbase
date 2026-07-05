<?php

namespace App\ValueObjects;

/**
 * EmailBodyEncryptionResult — return type of
 * EmailBodyEncryptionService::encrypt(). Forces every caller to
 * branch explicitly on succeeded rather than assuming a ciphertext is
 * always available — when succeeded is false, ciphertext and
 * encryptionKeyId are both null and the caller MUST NOT write any body
 * column at all (fail closed, never store plaintext as a fallback).
 */
class EmailBodyEncryptionResult
{
    public function __construct(
        public readonly bool $succeeded,
        public readonly ?string $ciphertext = null,
        public readonly ?int $encryptionKeyId = null,
        public readonly ?string $reason = null,
    ) {
    }

    public static function success(string $ciphertext, int $encryptionKeyId): self
    {
        return new self(succeeded: true, ciphertext: $ciphertext, encryptionKeyId: $encryptionKeyId);
    }

    public static function failure(string $reason): self
    {
        return new self(succeeded: false, reason: $reason);
    }
}
