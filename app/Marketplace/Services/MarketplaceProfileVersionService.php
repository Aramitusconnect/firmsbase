<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Marketplace\Enums\DataProvenanceSourceType;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\DirectoryProfileVersion;

/**
 * MarketplaceProfileVersionService — Mission 2 (MyAttorney Marketplace
 * Core), section 25. The sole write path for directory_profile_versions
 * — lightweight, not full event-sourcing (append one row per change,
 * never replays state from history).
 */
class MarketplaceProfileVersionService
{
    /**
     * @param  array<string, mixed>  $changedFields
     */
    public function record(
        DirectoryFirm $firm,
        array $changedFields,
        string $actorType,
        ?int $actorId,
        DataProvenanceSourceType $source,
    ): DirectoryProfileVersion {
        return DirectoryProfileVersion::create([
            'directory_firm_id' => $firm->id,
            'changed_fields' => $changedFields,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'source' => $source,
            'publication_state' => $firm->publication_state,
        ]);
    }
}
