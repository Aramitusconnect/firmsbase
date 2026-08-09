<?php

namespace App\Models;

use App\Enums\PaymentRequestAmountRule;
use App\Enums\PaymentRequestPurpose;
use App\Enums\PaymentRequestStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PaymentRequest — Payment Link / QR Routing phase. An ENTRY CHANNEL
 * only — see the create-table migration's own docblock for the full
 * "never a parallel ledger/payment system/trust classification system/
 * invoice system/accounting system" boundary this model must never
 * cross. It never decides PaymentClassification, never posts a journal
 * entry, and never writes a TrustLedgerEntry itself — PaymentRequestService
 * always delegates those decisions to the existing canonical services.
 *
 * `uuid` (HasPublicUuid) is the only identifier ever exposed in a
 * public URL/QR code.
 */
class PaymentRequest extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    protected $fillable = [
        'firm_id',
        'client_id',
        'matter_id',
        'invoice_id',
        'payment_plan_installment_id',
        'purpose',
        'amount_rule',
        'requested_amount_cents',
        'currency',
        'status',
        'expires_at',
        'activated_at',
        'revoked_at',
        'revoked_by_firm_user_id',
        'revoke_reason',
        'provider_transaction_id',
        'paid_amount_cents',
        'paid_at',
        'payment_id',
        'failure_reason',
        'created_by_firm_user_id',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => PaymentRequestPurpose::class,
            'amount_rule' => PaymentRequestAmountRule::class,
            'status' => PaymentRequestStatus::class,
            'requested_amount_cents' => 'integer',
            'paid_amount_cents' => 'integer',
            'expires_at' => 'datetime',
            'activated_at' => 'datetime',
            'revoked_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function paymentPlanInstallment(): BelongsTo
    {
        return $this->belongsTo(PaymentPlanInstallment::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'created_by_firm_user_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'revoked_by_firm_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentRequestEvent::class);
    }

    /**
     * Read-only, computed at call time — never cached on this row.
     * Null when the request has no invoice/installment target (a
     * standalone request, or one already validated at creation time to
     * use PaymentRequestAmountRule::Fixed/CustomAllowed instead).
     */
    public function targetRemainingCents(): ?int
    {
        if ($this->payment_plan_installment_id !== null) {
            $installment = $this->paymentPlanInstallment;

            return max(0, $installment->amount_cents - $installment->paid_amount_cents);
        }

        if ($this->invoice_id !== null) {
            $invoice = $this->invoice;

            return max(0, $invoice->total_cents - $invoice->amount_paid_cents);
        }

        return null;
    }

    public function isPayable(): bool
    {
        if ($this->status !== PaymentRequestStatus::Active) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }
}
