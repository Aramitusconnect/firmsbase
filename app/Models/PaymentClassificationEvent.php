<?php

namespace App\Models;

use App\Enums\PaymentClassification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PaymentClassificationEvent — append-only. requested_classification /
 * resolved_classification are the strict PaymentClassification enum
 * (never plain strings) — these are actual classification values.
 * event_type is a plain string (approved decision) for the narrative.
 * No uuid — internal audit log only.
 */
class PaymentClassificationEvent extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'firm_id',
        'payment_id',
        'event_type',
        'requested_classification',
        'resolved_classification',
        'reason',
        'actor_user_id',
    ];

    protected function casts(): array
    {
        return [
            'requested_classification' => PaymentClassification::class,
            'resolved_classification' => PaymentClassification::class,
            'created_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
