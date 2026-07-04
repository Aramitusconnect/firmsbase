<?php

namespace App\Models;

use App\Enums\FirmLeadStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * FirmLead — converted_client_id/converted_at are set ONLY by
 * LeadConversionService, never by direct attribute assignment
 * elsewhere in the codebase (project rule: a lead must not silently
 * become a client).
 */
class FirmLead extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'lead_source_id',
        'practice_area_interest_id',
        'name',
        'email',
        'phone',
        'status',
        'assigned_to',
        'converted_client_id',
        'converted_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => FirmLeadStatus::class,
            'converted_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class);
    }

    public function practiceAreaInterest(): BelongsTo
    {
        return $this->belongsTo(PracticeArea::class, 'practice_area_interest_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function convertedClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'converted_client_id');
    }

    public function consultations(): HasMany
    {
        return $this->hasMany(Consultation::class);
    }

    public function isConverted(): bool
    {
        return $this->status === FirmLeadStatus::Converted && ! is_null($this->converted_client_id);
    }
}
