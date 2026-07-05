<?php

namespace App\Models;

use App\Enums\FormDraftValueSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FormDraftValue — no firm_id, scoped transitively through
 * form_draft_id. form_mapping_rule_id (nullable) is what lets
 * FormReviewService::approve() trace every mapped value back to the
 * exact rule that produced it and check ITS current content_status
 * live, rather than trusting a stale generation-time flag.
 */
class FormDraftValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'form_draft_id',
        'form_field_id',
        'form_mapping_rule_id',
        'value',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'source' => FormDraftValueSource::class,
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

    public function formMappingRule(): BelongsTo
    {
        return $this->belongsTo(FormMappingRule::class);
    }
}
