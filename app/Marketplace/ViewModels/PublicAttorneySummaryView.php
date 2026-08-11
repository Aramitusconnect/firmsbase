<?php

declare(strict_types=1);

namespace App\Marketplace\ViewModels;

use App\Marketplace\Models\DirectoryAttorneyFirm;

/**
 * PublicAttorneySummaryView — Mission 2 (MyAttorney Marketplace
 * Core), section 41: the attorney-list entry embedded in a public
 * Firm profile. Deliberately narrower than PublicAttorneyProfile (no
 * biography/full practice-area list) — a summary card linking to the
 * attorney's own page, not a duplicate of it.
 */
final readonly class PublicAttorneySummaryView
{
    public function __construct(
        public string $slug,
        public string $name,
        public ?string $title,
        public bool $isPubliclyVisible,
    ) {}

    public static function fromRelationship(DirectoryAttorneyFirm $relationship): ?self
    {
        $attorney = $relationship->attorney;

        if ($attorney === null || ! $attorney->isPubliclyVisible() || ! $relationship->relationship_state->isPubliclyDisplayable()) {
            return null;
        }

        return new self(
            slug: $attorney->slug,
            name: $attorney->name,
            title: $relationship->title ?? $attorney->title,
            isPubliclyVisible: true,
        );
    }
}
