<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FormReviewChecklistItem — no firm_id (chosen approach, documented at
 * the migration): scoped transitively through form_draft_id. Backs
 * the WCAG "accessible checklist controls" readiness item.
 */
class FormReviewChecklistItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'form_draft_id',
        'checklist_code',
        'label',
        'is_checked',
        'checked_by_firm_user_id',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'is_checked' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }

    public function formDraft(): BelongsTo
    {
        return $this->belongsTo(FormDraft::class);
    }

    public function checkedByFirmUser(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'checked_by_firm_user_id');
    }
}
