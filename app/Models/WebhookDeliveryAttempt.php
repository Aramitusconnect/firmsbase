<?php

namespace App\Models;

use App\Enums\WebhookDeliveryAttemptOutcome;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WebhookDeliveryAttempt — append-only (correction #13), mirrors
 * TrustLedgerEntry's strict-immutability shape exactly: no uuid,
 * $timestamps = false (only `attempted_at` + `created_at` exist, no
 * `updated_at`), and the model's own booted() hook throws on ANY
 * update or delete of an existing row. webhook_secret_id records which
 * active secret signed this attempt (correction #7) — never the raw
 * secret itself. response_snippet must never contain secret/ciphertext/
 * signature material (enforced by WebhookDeliveryAttemptService at
 * write time, not by this model).
 */
class WebhookDeliveryAttempt extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'firm_id',
        'webhook_delivery_id',
        'webhook_secret_id',
        'attempt_number',
        'outcome',
        'http_status_code',
        'response_snippet',
        'attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'outcome' => WebhookDeliveryAttemptOutcome::class,
            'attempt_number' => 'integer',
            'http_status_code' => 'integer',
            'attempted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException(
                'webhook_delivery_attempts is append-only: an existing row can never be updated.'
            );
        });

        static::deleting(function () {
            throw new \LogicException(
                'webhook_delivery_attempts is append-only: an existing row can never be deleted.'
            );
        });
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(WebhookDelivery::class, 'webhook_delivery_id');
    }

    public function secret(): BelongsTo
    {
        return $this->belongsTo(WebhookSecret::class, 'webhook_secret_id');
    }
}
