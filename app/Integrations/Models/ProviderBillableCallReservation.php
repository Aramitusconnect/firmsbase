<?php

declare(strict_types=1);

namespace App\Integrations\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Firm;
use App\Models\FirmUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProviderBillableCallReservation — Direct `BelongsToTenant`, FORCE RLS.
 * The sole writer/transitioner is
 * `App\Integrations\Billing\ProviderUsageReservationService` — this
 * model itself carries no immutability guard (append-then-one-transition,
 * not literally append-only; see the create migration's own docblock).
 */
class ProviderBillableCallReservation extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    protected $table = 'provider_billable_call_reservations';

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_FINALIZED_BILLABLE = 'finalized_billable';

    public const STATUS_FINALIZED_NON_BILLABLE = 'finalized_non_billable';

    public const STATUS_FINALIZED_UNCERTAIN = 'finalized_uncertain';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'firm_id',
        'firm_integration_id',
        'provider_key',
        'product',
        'billing_operation',
        'environment',
        'rate_card_entry_id',
        'estimated_customer_price_cents',
        'quantity',
        'unit',
        'status',
        'idempotency_key',
        'correlation_id',
        'usage_record_id',
        'reserved_at',
        'provider_call_started_at',
        'expires_at',
        'finalized_at',
        'reserved_by_firm_user_id',
        'reservation_reason',
    ];

    protected function casts(): array
    {
        return [
            'estimated_customer_price_cents' => 'integer',
            'quantity' => 'integer',
            'reserved_at' => 'datetime',
            'provider_call_started_at' => 'datetime',
            'expires_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function firmIntegration(): BelongsTo
    {
        return $this->belongsTo(FirmIntegration::class);
    }

    public function rateCardEntry(): BelongsTo
    {
        return $this->belongsTo(ProviderRateCardEntry::class, 'rate_card_entry_id');
    }

    public function usageRecord(): BelongsTo
    {
        return $this->belongsTo(IntegrationUsageRecord::class, 'usage_record_id');
    }

    public function reservedByFirmUser(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'reserved_by_firm_user_id');
    }

    public function isReserved(): bool
    {
        return $this->status === self::STATUS_RESERVED;
    }

    /**
     * True once the reservation's outbound provider call has provably
     * been handed to `$providerCall()` — see the
     * `provider_call_started_at` migration's own docblock for the two
     * crash windows this distinguishes. A NULL here on an abandoned
     * reservation is the ONLY positive evidence this system ever has
     * that the provider was never contacted.
     */
    public function providerCallStarted(): bool
    {
        return $this->provider_call_started_at !== null;
    }

    /**
     * True for a `reserved` row whose TTL has elapsed without anything
     * finalizing it — i.e. a row `ExpireStaleProviderReservationsJob`
     * WOULD sweep to `expired` but has not swept yet (that job runs on a
     * coarse schedule with `WithoutOverlapping`, so it offers no
     * "swept before the next retry" guarantee and must never be relied
     * on as one).
     */
    public function isPastTtl(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
