<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DocumentChaseEvent — append-only. event_type is a plain string
 * (approved clarification), matching timeline_events/payment_plan_
 * events/payment_classification_events exactly.
 */
class DocumentChaseEvent extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'firm_id',
        'document_request_item_id',
        'document_chase_rule_id',
        'event_type',
        'metadata_json',
        'actor_user_id',
    ];

    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function documentRequestItem(): BelongsTo
    {
        return $this->belongsTo(DocumentRequestItem::class);
    }

    public function documentChaseRule(): BelongsTo
    {
        return $this->belongsTo(DocumentChaseRule::class);
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
