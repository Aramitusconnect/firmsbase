<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Consultation extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'firm_lead_id',
        'consultation_outcome_id',
        'scheduled_at',
        'held_at',
        'notes',
        'converted',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'held_at' => 'datetime',
            'converted' => 'boolean',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function firmLead(): BelongsTo
    {
        return $this->belongsTo(FirmLead::class);
    }

    public function outcome(): BelongsTo
    {
        return $this->belongsTo(ConsultationOutcome::class, 'consultation_outcome_id');
    }
}
