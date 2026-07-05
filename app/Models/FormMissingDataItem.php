<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FormMissingDataItem — no firm_id, scoped transitively through
 * form_draft_id. resolved_at is set by FormMissingDataDetectionService
 * on a re-scan that finds the field now populated.
 */
class FormMissingDataItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'form_draft_id',
        'form_field_id',
        'detected_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function formDraft(): BelongsTo
    {
        return $this->belongsTo(FormDraft::class);
    }

    public function formField(): BelongsTo
    {
        return $this->belongsTo(FormField::class);
    }

    public function isResolved(): bool
    {
        return ! is_null($this->resolved_at);
    }
}
