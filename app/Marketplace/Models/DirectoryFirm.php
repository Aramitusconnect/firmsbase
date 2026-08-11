<?php

declare(strict_types=1);

namespace App\Marketplace\Models;

use App\Marketplace\Enums\DataProvenanceSourceType;
use App\Marketplace\Enums\DirectoryFirmProfileLevel;
use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Models\Concerns\HasMarketplaceSlug;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Firm;
use App\Models\Language;
use App\Models\PracticeArea;
use Database\Factories\DirectoryFirmFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DirectoryFirm — Mission 2 (MyAttorney Marketplace Core), section 8.
 * See database/migrations/2026_11_10_100001_create_directory_firms_table.php
 * for the full ownership/RLS-exemption rationale.
 */
class DirectoryFirm extends Model
{
    use HasFactory, HasMarketplaceSlug, HasPublicUuid;

    protected $fillable = [
        'firm_id',
        'slug',
        'legal_name',
        'display_name',
        'name_normalized',
        'phone',
        'website',
        'public_email',
        'founding_year',
        'description',
        'consultation_modes',
        'accepting_inquiries',
        'is_claimed',
        'claimed_at',
        'is_marketplace_member',
        'membership_activated_at',
        'publication_state',
        'source_type',
        'source_reference',
        'imported_at',
        'last_verified_at',
        'last_confirmed_by_firm_at',
        'completeness_score',
    ];

    protected function casts(): array
    {
        return [
            'consultation_modes' => 'array',
            'accepting_inquiries' => 'boolean',
            'is_claimed' => 'boolean',
            'claimed_at' => 'datetime',
            'is_marketplace_member' => 'boolean',
            'membership_activated_at' => 'datetime',
            'publication_state' => DirectoryPublicationState::class,
            'source_type' => DataProvenanceSourceType::class,
            'imported_at' => 'datetime',
            'last_verified_at' => 'datetime',
            'last_confirmed_by_firm_at' => 'datetime',
            'founding_year' => 'integer',
            'completeness_score' => 'integer',
        ];
    }

    protected static function newFactory(): DirectoryFirmFactory
    {
        return DirectoryFirmFactory::new();
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function offices(): HasMany
    {
        return $this->hasMany(FirmOffice::class);
    }

    public function attorneyRelationships(): HasMany
    {
        return $this->hasMany(DirectoryAttorneyFirm::class);
    }

    public function practiceAreas(): BelongsToMany
    {
        return $this->belongsToMany(PracticeArea::class, 'directory_firm_practice_areas')
            ->withPivot('source_type')
            ->withTimestamps();
    }

    public function languages(): BelongsToMany
    {
        return $this->belongsToMany(Language::class, 'directory_firm_languages')
            ->withPivot('source_type')
            ->withTimestamps();
    }

    /**
     * Section 15: always derived from is_claimed/is_marketplace_member,
     * never an independently stored, driftable column.
     */
    public function profileLevel(): DirectoryFirmProfileLevel
    {
        if ($this->is_marketplace_member) {
            return DirectoryFirmProfileLevel::VerifiedMember;
        }

        if ($this->is_claimed) {
            return DirectoryFirmProfileLevel::ClaimedProfile;
        }

        return DirectoryFirmProfileLevel::PublicListing;
    }

    public function isPubliclyVisible(): bool
    {
        return $this->publication_state->isPubliclyVisible();
    }
}
