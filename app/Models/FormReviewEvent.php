<?php

namespace App\Models;

use App\Enums\FormReviewEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FormReviewEvent — pure audit row. Has firm_id for direct queries but
 * does NOT use BelongsToTenant (Phase 8/9 audit-row precedent).
 * Append-only (Section 39A-6 Wave 6 companion fix, required not
 * optional — mirrors AiApprovalEvent's/EmailSyncEvent's exact
 * booted() guard, since this table has neither BelongsToTenant nor a
 * pre-existing append-only guard of its own): no updated_at, and the
 * model's booted() hook throws on any update/delete of an existing
 * row. The only writer is FormReviewService::recordEvent().
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

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('form_review_events is append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new \LogicException('form_review_events is append-only and cannot be deleted.');
        });
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
