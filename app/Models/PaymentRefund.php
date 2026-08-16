<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentRefundState;
use App\Integrations\Models\FirmIntegration;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PaymentRefund — FirmsVault Pay Gate A2 (v1.4 §24-§28). A
 * provider-executed refund against one captured PaymentAttempt.
 *
 * Distinct from App\Models\PaymentReversal (the firm's own operating
 * refund record, written by OperatingPaymentRefundService and left
 * completely untouched by this gate) and from PlatformRefund (SaaS
 * subscription billing). This is the provider-side object: it holds
 * refundable CAPACITY from the moment it is reserved, and keeps holding
 * it when the outcome is unknown.
 */
class PaymentRefund extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    protected $fillable = [
        'firm_id',
        'payment_attempt_id',
        'provider_command_id',
        'firm_integration_id',
        'state',
        'amount_cents',
        'currency',
        'reason',
        'provider_reference',
        'failure_reason',
        'reserved_at',
        'reservation_expires_at',
        'submitted_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'state' => PaymentRefundState::class,
            'amount_cents' => 'integer',
            'reserved_at' => 'datetime',
            'reservation_expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $refund) {
            $immutable = array_intersect(
                array_keys($refund->getDirty()),
                ['firm_id', 'payment_attempt_id', 'amount_cents', 'currency'],
            );

            if ($immutable !== []) {
                throw new \LogicException(
                    'payment_refunds: refusing to change immutable field(s) ['
                    .implode(', ', $immutable).'] — the amount of money a refund reserves can never move.'
                );
            }
        });
    }

    public function holdsCapacity(): bool
    {
        return $this->state->holdsRefundableCapacity();
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }

    public function providerCommand(): BelongsTo
    {
        return $this->belongsTo(ProviderCommand::class);
    }

    public function firmIntegration(): BelongsTo
    {
        return $this->belongsTo(FirmIntegration::class);
    }
}
