<?php

namespace App\Models;

use App\Enums\FormReviewEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FormReviewEvent — pure audit row. Has firm_id for direct queries but
 * does NOT use BelongsToTenant (Phase 8/9 audit-row precedent).
 */
class FormReviewEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'firm_id',
        'form_draft_id',
        'event_type',
        'actor_firm_user_id',
        'notes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => FormReviewEventType::class,
            'created_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function formDraft(): BelongsTo
    {
        return $this->belongsTo(FormDraft::class);
    }

    public function actorFirmUser(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'actor_firm_user_id');
    }
}
