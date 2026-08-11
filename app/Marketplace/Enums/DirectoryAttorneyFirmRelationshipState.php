<?php

declare(strict_types=1);

namespace App\Marketplace\Enums;

/**
 * DirectoryAttorneyFirmRelationshipState — Mission 2, section 11. An
 * attorney moving firms transitions this state on the existing
 * relationship row (unique per attorney/firm pair) rather than
 * spawning a duplicate attorney record.
 */
enum DirectoryAttorneyFirmRelationshipState: string
{
    case Current = 'current';
    case Former = 'former';
    case PendingVerification = 'pending_verification';
    case Disputed = 'disputed';
    case Unpublished = 'unpublished';

    public function isPubliclyDisplayable(): bool
    {
        return match ($this) {
            self::Current, self::Former => true,
            self::PendingVerification, self::Disputed, self::Unpublished => false,
        };
    }
}
