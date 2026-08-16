<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentAttemptState;
use App\Integrations\Models\FirmIntegration;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PaymentAttempt — FirmsVault Pay Gate A2 (v1.4 §22/§23). One execution
 * of one frozen PaymentIntent, 1:1 with its ProviderCommand.
 *
 * The state machine lives in App\Enums\PaymentAttemptState and is
 * enforced by App\Services\Pay\PaymentAttemptService::transition().
 * OutcomeUnknown has no outgoing transitions by design: an undetermined
 * economic outcome must be resolved by provider-side recovery against
 * THIS attempt, never by starting another one.
 */
class PaymentAttempt extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    protected $fillable = [
        'firm_id',
        'payment_intent_id',
        'provider_command_id',
        'firm_integration_id',
        'state',
        'amount_cents',
        'currency',
        'provider_reference',
        'failure_reason',
        'payment_id',
        'submitted_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'state' => PaymentAttemptState::class,
            'amount_cents' => 'integer',
            'submitted_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $attempt) {
            // The economic anchor of an attempt never moves.
            $immutable = array_intersect(
                array_keys($attempt->getDirty()),
                ['firm_id', 'payment_intent_id', 'amount_cents', 'currency'],
            );

            if ($immutable !== []) {
                throw new \LogicException(
                    'payment_attempts: refusing to change immutable field(s) ['
                    .implode(', ', $immutable).'] on an existing attempt.'
                );
            }

            // provider_command_id is WRITE-ONCE rather than never-write.
            // The attempt and its command are created in the same
            // transaction, but the command's aggregate_id is the
            // attempt's own id, so the binding can only be made after
            // the attempt row exists. Binding null -> a command is
            // therefore legal exactly once; re-binding an attempt to a
            // DIFFERENT economic instruction never is, which is the
            // invariant that actually matters (and which the UNIQUE
            // index on provider_command_id also enforces from the other
            // direction).
            if ($attempt->isDirty('provider_command_id') && $attempt->getOriginal('provider_command_id') !== null) {
                throw new \LogicException(
                    'payment_attempts: an attempt is bound to exactly one provider command for its '
                    .'entire life — refusing to re-bind attempt to a different economic instruction.'
                );
            }
        });
    }

    /**
     * Refundable capacity is the captured amount. A non-captured attempt
     * has none — there is no money to give back.
     */
    public function refundableCapacityCents(): int
    {
        return $this->state === PaymentAttemptState::Captured ? (int) $this->amount_cents : 0;
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class);
    }

    public function providerCommand(): BelongsTo
    {
        return $this->belongsTo(ProviderCommand::class);
    }

    public function firmIntegration(): BelongsTo
    {
        return $this->belongsTo(FirmIntegration::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }
}
