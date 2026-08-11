<?php

declare(strict_types=1);

namespace App\Marketplace\Enums;

/**
 * DirectoryPublicationState — Mission 2, section 68. Shared publication
 * lifecycle for every public-facing directory entity (Firm, Office,
 * Attorney). A claimed Firm does not automatically publish every
 * submitted change when moderation/verification rules require review —
 * this enum is what a mutation transitions, never an implicit side
 * effect of "the record exists."
 */
enum DirectoryPublicationState: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Suspended = 'suspended';
    case Removed = 'removed';
    case Archived = 'archived';

    public function isPubliclyVisible(): bool
    {
        return $this === self::Published;
    }
}
