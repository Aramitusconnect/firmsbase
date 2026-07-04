<?php

namespace App\Models;

use App\Enums\FirmActivationEventStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FirmActivationEvent — always firm-scoped (firm_id is NOT NULL,
 * unlike every other new Phase 5 model) — this is the one Phase 5
 * model that uses BelongsToTenant. Append-only (const UPDATED_AT =
 * null). event_type is a plain string (approved clarification);
 * status is the strict FirmActivationEventStatus outcome. No uuid —
 * internal audit trail, accessed only through its parent Firm.
 */
class FirmActivationEvent extends Model
{
    use HasFactory, BelongsToTenant;

    const UPDATED_AT = null;

    protected $fillable = [
        'firm_id',
        'event_type',
        'status',
        'checklist_item_key',
        'blocking_reason',
        'actor_user_id',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'status' => FirmActivationEventStatus::class,
            'metadata_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
