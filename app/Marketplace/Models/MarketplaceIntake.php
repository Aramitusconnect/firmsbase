<?php

declare(strict_types=1);

namespace App\Marketplace\Models;

use App\Enums\MarketplaceIntakeStatus;
use App\Models\Client;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Firm;
use App\Models\FirmLead;
use App\Models\PracticeArea;
use Database\Factories\MarketplaceIntakeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MarketplaceIntake — Mission 3 (MyAttorney Conversion + AI Intake),
 * checkpoint 1. See its own migration
 * (database/migrations/2026_11_12_100001_create_marketplace_intakes_table.php)
 * for the full domain rationale, including why this is a genuinely
 * new table rather than a reuse of FirmLead.
 *
 * converted_firm_lead_id / converted_client_id / converted_at are set
 * ONLY by ConvertMarketplaceProspectService (checkpoint 11) — mirrors
 * FirmLead's own "converted_client_id set only by LeadConversionService"
 * project rule.
 */
class MarketplaceIntake extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    protected $fillable = [
        'firm_id',
        'directory_firm_id',
        'practice_area_id',
        'status',
        'prospect_name',
        'prospect_email',
        'prospect_phone',
        'structured_data',
        'submitted_at',
        'under_review_at',
        'accepted_at',
        'declined_at',
        'decline_reason',
        'converted_firm_lead_id',
        'converted_client_id',
        'converted_at',
        'expires_at',
        'last_resumed_at',
        'abandoned_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => MarketplaceIntakeStatus::class,
            'structured_data' => 'array',
            'submitted_at' => 'datetime',
            'under_review_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'converted_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_resumed_at' => 'datetime',
            'abandoned_at' => 'datetime',
        ];
    }

    protected static function newFactory(): MarketplaceIntakeFactory
    {
        return MarketplaceIntakeFactory::new();
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function directoryFirm(): BelongsTo
    {
        return $this->belongsTo(DirectoryFirm::class);
    }

    public function practiceArea(): BelongsTo
    {
        return $this->belongsTo(PracticeArea::class);
    }

    public function convertedFirmLead(): BelongsTo
    {
        return $this->belongsTo(FirmLead::class, 'converted_firm_lead_id');
    }

    public function convertedClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'converted_client_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MarketplaceIntakeEvent::class);
    }

    public function isConverted(): bool
    {
        return $this->status === MarketplaceIntakeStatus::Converted && ! is_null($this->converted_client_id);
    }
}
