<?php

namespace App\ValueObjects;

class EmailAttachmentPromotionResult
{
    public function __construct(
        public readonly bool $promoted,
        public readonly ?int $documentId = null,
        public readonly ?string $blockedReason = null,
    ) {
    }

    public static function promoted(int $documentId): self
    {
        return new self(promoted: true, documentId: $documentId);
    }

    public static function blocked(string $reason): self
    {
        return new self(promoted: false, blockedReason: $reason);
    }
}
