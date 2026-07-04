<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BillingAccount — platform billing only. Must never be mixed with
 * firm-client invoice/payment records (project rule 4). Not tenant-owned
 * (it is part of the tenancy/commercial boundary), so it does not use
 * BelongsToTenant.
 *
 * Phase 6 addition: organization_id (nullable — a billing account can
 * optionally belong to an organization for consolidated billing),
 * bill_to_contact, payment_method_ref (a placeholder reference only —
 * no real Stripe/payment-method data is stored here, see
 * FakeStripeGateway), and the full platform billing relation set
 * (subscriptions/invoices/payments/payment attempts/billing events/
 * usage rollups). consolidation_mode is NOT duplicated here — it
 * already lives on organizations (Phase 1); see the Phase 6 manifest's
 * note on this catalog imprecision.
 *
 * Phase 7 addition: commissionEvents() — commission_events is keyed to
 * billing_account_id (project rule: commission must key to platform
 * billing only, never firm-client invoices/payments).
 */
class BillingAccount extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'organization_id',
        'name',
        'status',
        'billing_email',
        'bill_to_contact',
        'payment_method_ref',
    ];

    protected function casts(): array
    {
        return [
            'status' => RecordStatus::class,
        ];
    }

    public function firms(): HasMany
    {
        return $this->hasMany(Firm::class);
    }

    /**
     * Phase 6 additions below.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function orgLicenses(): HasMany
    {
        return $this->hasMany(OrgLicense::class);
    }

    public function firmLicenses(): HasMany
    {
        return $this->hasMany(FirmLicense::class);
    }

    public function platformSubscriptions(): HasMany
    {
        return $this->hasMany(PlatformSubscription::class);
    }

    public function platformInvoices(): HasMany
    {
        return $this->hasMany(PlatformInvoice::class);
    }

    public function platformPayments(): HasMany
    {
        return $this->hasMany(PlatformPayment::class);
    }

    public function platformPaymentAttempts(): HasMany
    {
        return $this->hasMany(PlatformPaymentAttempt::class);
    }

    public function platformBillingEvents(): HasMany
    {
        return $this->hasMany(PlatformBillingEvent::class);
    }

    public function usageRollups(): HasMany
    {
        return $this->hasMany(UsageRollup::class);
    }

    /**
     * Phase 7 addition below.
     */
    public function commissionEvents(): HasMany
    {
        return $this->hasMany(CommissionEvent::class);
    }
}
