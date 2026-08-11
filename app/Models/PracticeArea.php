<?php

namespace App\Models;

use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryFirm;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PracticeArea — GLOBAL platform catalog (approved decision). Firms
 * enable/select from this via FirmPracticeArea; no per-firm custom
 * core practice areas in Phase 2. No BelongsToTenant, no uuid.
 *
 * Mission 2 (MyAttorney Marketplace Core) addition: this is also the
 * marketplace's own single controlled practice-area taxonomy (section
 * 12) — reused directly rather than duplicated, so a firm's internal
 * specialization list can never drift from what is actually
 * searchable in the marketplace. `slug`/`is_marketplace_visible`/
 * `sort_order`/`synonyms` are purely additive marketplace-facing
 * columns; every pre-existing column/relationship above is untouched.
 * `is_marketplace_visible` is deliberately independent of `is_active`
 * — a category can remain active for internal matter-type/template
 * purposes while never appearing in public marketplace search
 * (section 13: a factual "practices in" association is not the same
 * as choosing to publicly market it).
 */
class PracticeArea extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'slug',
        'name',
        'description',
        'is_active',
        'is_marketplace_visible',
        'sort_order',
        'synonyms',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_marketplace_visible' => 'boolean',
            'sort_order' => 'integer',
            'synonyms' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function matterTypes(): HasMany
    {
        return $this->hasMany(MatterType::class);
    }

    public function templatePacks(): HasMany
    {
        return $this->hasMany(TemplatePack::class);
    }

    public function firmPracticeAreas(): HasMany
    {
        return $this->hasMany(FirmPracticeArea::class);
    }

    public function directoryFirms(): BelongsToMany
    {
        return $this->belongsToMany(DirectoryFirm::class, 'directory_firm_practice_areas')
            ->withPivot('source_type')
            ->withTimestamps();
    }

    public function directoryAttorneys(): BelongsToMany
    {
        return $this->belongsToMany(DirectoryAttorney::class, 'directory_attorney_practice_areas')
            ->withPivot('source_type')
            ->withTimestamps();
    }
}
