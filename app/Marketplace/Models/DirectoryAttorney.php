<?php

declare(strict_types=1);

namespace App\Marketplace\Models;

use App\Marketplace\Enums\DataProvenanceSourceType;
use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Models\Concerns\HasMarketplaceSlug;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Language;
use App\Models\PracticeArea;
use Database\Factories\DirectoryAttorneyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DirectoryAttorney — Mission 2 (MyAttorney Marketplace Core), section
 * 10. See
 * database/migrations/2026_11_10_100003_create_directory_attorneys_table.php
 * for the full rationale.
 */
class DirectoryAttorney extends Model
{
    use HasFactory, HasMarketplaceSlug, HasPublicUuid;

    protected $fillable = [
        'slug',
        'name',
        'name_normalized',
        'title',
        'biography',
        'photo_path',
        'bar_number',
        'license_jurisdictions',
        'publication_state',
        'source_type',
        'source_reference',
        'imported_at',
        'last_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'license_jurisdictions' => 'array',
            'publication_state' => DirectoryPublicationState::class,
            'source_type' => DataProvenanceSourceType::class,
            'imported_at' => 'datetime',
            'last_verified_at' => 'datetime',
        ];
    }

    protected static function newFactory(): DirectoryAttorneyFactory
    {
        return DirectoryAttorneyFactory::new();
    }

    public function firmRelationships(): HasMany
    {
        return $this->hasMany(DirectoryAttorneyFirm::class);
    }

    public function practiceAreas(): BelongsToMany
    {
        return $this->belongsToMany(PracticeArea::class, 'directory_attorney_practice_areas')
            ->withPivot('source_type')
            ->withTimestamps();
    }

    public function languages(): BelongsToMany
    {
        return $this->belongsToMany(Language::class, 'directory_attorney_languages')
            ->withPivot('source_type')
            ->withTimestamps();
    }

    public function isPubliclyVisible(): bool
    {
        return $this->publication_state->isPubliclyVisible();
    }
}
