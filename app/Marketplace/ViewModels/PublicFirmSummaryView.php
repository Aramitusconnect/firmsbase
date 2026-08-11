<?php

declare(strict_types=1);

namespace App\Marketplace\ViewModels;

use App\Marketplace\Enums\DirectoryAttorneyFirmRelationshipState;
use App\Marketplace\Models\DirectoryAttorneyFirm;

/**
 * PublicFirmSummaryView — Mission 2 (MyAttorney Marketplace Core),
 * section 42: the firm-affiliation entry embedded in a public
 * Attorney profile.
 */
final readonly class PublicFirmSummaryView
{
    public function __construct(
        public string $slug,
        public string $displayName,
        public ?string $title,
        public bool $isCurrent,
    ) {}

    public static function fromRelationship(DirectoryAttorneyFirm $relationship): ?self
    {
        $firm = $relationship->firm;

        if ($firm === null || ! $firm->isPubliclyVisible() || ! $relationship->relationship_state->isPubliclyDisplayable()) {
            return null;
        }

        return new self(
            slug: $firm->slug,
            displayName: $firm->display_name,
            title: $relationship->title,
            isCurrent: $relationship->relationship_state === DirectoryAttorneyFirmRelationshipState::Current,
        );
    }
}
