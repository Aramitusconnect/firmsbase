<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PaymentPlanEvent — append-only. event_type is a plain string
 * (approved decision), matching CommunicationConsentEvent.action and
 * TimelineEvent.event_type exactly. No uuid — internal audit log only,
 * same reasoning as SecurityEvent.
 */
class PaymentPlanEvent extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'firm_id',
        'payment_plan_id',
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

    public function paymentPlan(): BelongsTo
    {
        return $this->belongsTo(PaymentPlan::class);
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
