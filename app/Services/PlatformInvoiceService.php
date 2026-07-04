<?php

namespace App\Services;

use App\Enums\PlatformInvoiceStatus;
use App\Models\BillingAccount;
use App\Models\Firm;
use App\Models\PlatformInvoice;
use App\Models\PlatformInvoiceLine;
use App\Models\PlatformSubscription;

/**
 * PlatformInvoiceService — the only place platform_invoices and
 * platform_invoice_lines rows are created. Deliberately separate from
 * Phase 3's InvoiceService (firm-client invoices) — never shares a
 * table, an enum, or a code path (project rule 1). addLine() supports
 * "consolidated invoices with per-firm usage attribution" (project
 * rule 4) via the optional $firm/$usageMetric parameters.
 */
class PlatformInvoiceService
{
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

    public function finalize(PlatformInvoice $invoice): PlatformInvoice
    {
        return tap($invoice)->update(['status' => PlatformInvoiceStatus::Open])->fresh();
    }

    public function markPaid(PlatformInvoice $invoice): PlatformInvoice
    {
        return tap($invoice)->update(['status' => PlatformInvoiceStatus::Paid, 'paid_at' => now()])->fresh();
    }

    public function void(PlatformInvoice $invoice): PlatformInvoice
    {
        return tap($invoice)->update(['status' => PlatformInvoiceStatus::Void, 'voided_at' => now()])->fresh();
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
