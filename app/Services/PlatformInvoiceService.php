<?php

namespace App\Services;

use App\Enums\PlatformInvoiceStatus;
use App\Models\BillingAccount;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\PlatformInvoice;
use App\Models\PlatformInvoiceLine;
use App\Models\PlatformSubscription;

/**
 * PlatformInvoiceService — the only place platform_invoices and
 * platform_invoice_lines rows are created. Deliberately separate from
 * this repo's own internal Phase 3's InvoiceService (firm-client
 * invoices) — never shares a table, an enum, or a code path (project
 * rule 1; note this repo's internal phase numbering is unrelated to
 * the FirmsVault Admin Control Center mission's Phase 3, which is the
 * one adding the actor/audit plumbing directly below). addLine()
 * supports "consolidated invoices with per-firm usage attribution"
 * (project rule 4) via the optional $firm/$usageMetric parameters.
 *
 * FirmsVault Admin Control Center Phase 3 ("Billing and Commercial
 * Administration") addition: finalize()/void() now accept an optional
 * PlatformAdmin $actor and, when one is supplied, record a
 * PlatformAdminAuditEventRecorder::recordPlatformEvent() row (the
 * firm-less variant — a PlatformInvoice is not tied to one firm; it is
 * keyed to billing_account_id, which can span an organization's
 * multiple firms). markPaid() is deliberately NOT touched here — see
 * that method's own docblock. When $actor is null (every existing
 * caller — no app-level call site currently passes one; only tests
 * call finalize()/void() directly today) behavior is byte-for-byte
 * unchanged from before this addition.
 */
class PlatformInvoiceService
{
    private const AUDIT_CATEGORY = 'platform_billing';

    public function __construct(
        private readonly PlatformAdminAuditEventRecorder $auditRecorder = new PlatformAdminAuditEventRecorder,
    ) {}

    public function createDraftInvoice(
        BillingAccount $billingAccount,
        \DateTimeInterface $periodStartsAt,
        \DateTimeInterface $periodEndsAt,
        ?PlatformSubscription $subscription = null,
        ?\DateTimeInterface $dueAt = null,
    ): PlatformInvoice {
        return PlatformInvoice::create([
            'billing_account_id' => $billingAccount->id,
            'platform_subscription_id' => $subscription?->id,
            'status' => PlatformInvoiceStatus::Draft,
            'period_starts_at' => $periodStartsAt,
            'period_ends_at' => $periodEndsAt,
            'due_at' => $dueAt,
        ]);
    }

    public function addLine(
        PlatformInvoice $invoice,
        string $description,
        int $quantity,
        int $unitAmountCents,
        ?Firm $firm = null,
        ?string $usageMetric = null,
    ): PlatformInvoiceLine {
        $line = PlatformInvoiceLine::create([
            'platform_invoice_id' => $invoice->id,
            'firm_id' => $firm?->id,
            'description' => $description,
            'quantity' => $quantity,
            'unit_amount_cents' => $unitAmountCents,
            'amount_cents' => $quantity * $unitAmountCents,
            'usage_metric' => $usageMetric,
        ]);

        $this->recalculateTotals($invoice->fresh());

        return $line;
    }

    public function finalize(PlatformInvoice $invoice, ?PlatformAdmin $actor = null): PlatformInvoice
    {
        $finalized = tap($invoice)->update(['status' => PlatformInvoiceStatus::Open])->fresh();

        if ($actor !== null) {
            $this->auditRecorder->recordPlatformEvent(
                $actor,
                'invoice_finalized',
                self::AUDIT_CATEGORY,
                [
                    'platform_invoice_id' => $finalized->id,
                    'billing_account_id' => $finalized->billing_account_id,
                    'resulting_status' => $finalized->status->value,
                ],
            );
        }

        return $finalized;
    }

    /**
     * Deliberately NOT given an actor/audit parameter in this phase.
     * markPaid() is normally only ever called from inside
     * PlatformPaymentService::attemptPayment() after a (simulated)
     * gateway confirmation — exposing it as a direct admin action would
     * let an invoice show Paid with no corresponding PlatformPayment
     * row behind it, indistinguishable from a real, gateway-confirmed
     * payment in every existing query/report (e.g.
     * CommissionEligibilityService keys eligibility off exactly this
     * status). Per the architecture investigation's Open Decision 5,
     * that needs its own provenance mechanism (e.g. a
     * paid_manually_by/paid_manually_reason pair) before it is safe to
     * expose — out of this phase's scope.
     */
    public function markPaid(PlatformInvoice $invoice): PlatformInvoice
    {
        return tap($invoice)->update(['status' => PlatformInvoiceStatus::Paid, 'paid_at' => now()])->fresh();
    }

    public function void(PlatformInvoice $invoice, ?PlatformAdmin $actor = null): PlatformInvoice
    {
        $voided = tap($invoice)->update(['status' => PlatformInvoiceStatus::Void, 'voided_at' => now()])->fresh();

        if ($actor !== null) {
            $this->auditRecorder->recordPlatformEvent(
                $actor,
                'invoice_voided',
                self::AUDIT_CATEGORY,
                [
                    'platform_invoice_id' => $voided->id,
                    'billing_account_id' => $voided->billing_account_id,
                    'resulting_status' => $voided->status->value,
                ],
            );
        }

        return $voided;
    }

    private function recalculateTotals(PlatformInvoice $invoice): void
    {
        $subtotal = (int) $invoice->lines()->sum('amount_cents');

        $invoice->update([
            'subtotal_cents' => $subtotal,
            'total_cents' => $subtotal + $invoice->tax_cents,
        ]);
    }
}
