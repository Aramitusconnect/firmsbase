<?php

namespace App\Services;

use App\Enums\InvoiceLineType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Client;
use App\Models\Firm;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Matter;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\TimeEntryApprovalService;
use Illuminate\Support\Facades\DB;

/**
 * InvoiceDraftingService — one unified service for both time-based
 * drafting and flat-fee creation (approved decision — no separate
 * FlatFeeInvoiceService). All Invoice status transitions live here;
 * amount fields are recomputed from invoice_lines every time, never
 * hand-set elsewhere.
 */
class InvoiceDraftingService
{
    public function __construct(
        private TimeEntryApprovalService $approvals,
        private TimelineEventRecorder $timeline,
    ) {
    }

    /**
     * Drafts an invoice from already-approved, billable time entries.
     * Every entry must belong to the same firm/client and already be
     * TimeEntryStatus::Approved + is_billable — anything else throws,
     * nothing is silently skipped.
     *
     * @param  array<int, TimeEntry>  $timeEntries
     */
    public function draftFromTimeEntries(
        Firm $firm,
        Client $client,
        array $timeEntries,
        ?Matter $matter = null,
        ?User $createdBy = null,
    ): Invoice {
        if (empty($timeEntries)) {
            throw new \InvalidArgumentException('At least one time entry is required to draft an invoice.');
        }

        foreach ($timeEntries as $entry) {
            if ($entry->firm_id !== $firm->id || $entry->client_id !== $client->id) {
                throw new \RuntimeException('All time entries must belong to the given firm and client.');
            }

            if (! $entry->isEligibleForInvoicing()) {
                throw new \RuntimeException("TimeEntry #{$entry->id} is not an approved, billable entry.");
            }
        }

        return DB::transaction(function () use ($firm, $client, $matter, $timeEntries, $createdBy) {
            $invoice = Invoice::create([
                'firm_id' => $firm->id,
                'client_id' => $client->id,
                'matter_id' => $matter?->id,
                'invoice_type' => InvoiceType::TimeAndExpense,
                'status' => InvoiceStatus::Draft,
                'created_by' => $createdBy?->id,
            ]);

            $sortOrder = 0;

            foreach ($timeEntries as $entry) {
                $rateCents = $entry->billing_rate_cents_snapshot ?? 0;
                $hours = round($entry->seconds / 3600, 4);
                $amountCents = (int) round($hours * $rateCents);

                InvoiceLine::create([
                    'invoice_id' => $invoice->id,
                    'time_entry_id' => $entry->id,
                    'line_type' => InvoiceLineType::TimeEntry,
                    'description' => $entry->description ?? 'Time entry',
                    'quantity' => $hours,
                    'rate_cents' => $rateCents,
                    'amount_cents' => $amountCents,
                    'sort_order' => $sortOrder++,
                ]);

                $this->approvals->markInvoiced($entry);
            }

            $this->recomputeTotals($invoice);

            $this->timeline->record($firm, 'invoice_drafted', $invoice, $createdBy, [
                'invoice_id' => $invoice->id,
                'invoice_type' => InvoiceType::TimeAndExpense->value,
            ]);

            return $invoice->fresh('lines');
        });
    }

    public function createFlatFee(
        Firm $firm,
        Client $client,
        string $description,
        int $amountCents,
        ?Matter $matter = null,
        ?User $createdBy = null,
    ): Invoice {
        return DB::transaction(function () use ($firm, $client, $matter, $description, $amountCents, $createdBy) {
            $invoice = Invoice::create([
                'firm_id' => $firm->id,
                'client_id' => $client->id,
                'matter_id' => $matter?->id,
                'invoice_type' => InvoiceType::FlatFee,
                'status' => InvoiceStatus::Draft,
                'created_by' => $createdBy?->id,
            ]);

            InvoiceLine::create([
                'invoice_id' => $invoice->id,
                'line_type' => InvoiceLineType::FlatFee,
                'description' => $description,
                'quantity' => 1,
                'rate_cents' => $amountCents,
                'amount_cents' => $amountCents,
                'sort_order' => 0,
            ]);

            $this->recomputeTotals($invoice);

            $this->timeline->record($firm, 'invoice_drafted', $invoice, $createdBy, [
                'invoice_id' => $invoice->id,
                'invoice_type' => InvoiceType::FlatFee->value,
            ]);

            return $invoice->fresh('lines');
        });
    }

    /**
     * "approved charges" per the PDF Scope — a manual charge line
     * added to an invoice that has not yet left Draft status.
     */
    public function addManualCharge(Invoice $invoice, string $description, int $amountCents): InvoiceLine
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            throw new \RuntimeException('Manual charges can only be added while the invoice is a draft.');
        }

        $line = InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'line_type' => InvoiceLineType::ManualCharge,
            'description' => $description,
            'quantity' => 1,
            'rate_cents' => $amountCents,
            'amount_cents' => $amountCents,
            'sort_order' => $invoice->lines()->count(),
        ]);

        $this->recomputeTotals($invoice);

        return $line;
    }

    public function submitForReview(Invoice $invoice): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            throw new \RuntimeException('Only a draft invoice can be submitted for review.');
        }

        $invoice->update(['status' => InvoiceStatus::PendingReview]);

        return $invoice->fresh();
    }

    public function approve(Invoice $invoice): Invoice
    {
        if ($invoice->status !== InvoiceStatus::PendingReview) {
            throw new \RuntimeException('Only a pending-review invoice can be approved.');
        }

        $invoice->update(['status' => InvoiceStatus::Approved, 'issued_at' => now()]);

        return $invoice->fresh();
    }

    public function send(Invoice $invoice): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Approved) {
            throw new \RuntimeException('Only an approved invoice can be sent.');
        }

        $invoice->update(['status' => InvoiceStatus::Sent, 'sent_at' => now()]);

        $this->timeline->record($invoice->firm, 'invoice_sent', $invoice, null, [
            'invoice_id' => $invoice->id,
        ]);

        return $invoice->fresh();
    }

    public function void(Invoice $invoice, ?string $reason = null): Invoice
    {
        if (in_array($invoice->status, [InvoiceStatus::Void, InvoiceStatus::Paid, InvoiceStatus::Refunded], true)) {
            throw new \RuntimeException('This invoice cannot be voided from its current status.');
        }

        $invoice->update(['status' => InvoiceStatus::Void, 'voided_at' => now()]);

        return $invoice->fresh();
    }

    /**
     * Recomputes subtotal_cents/total_cents from the invoice's own
     * lines. Phase 3 has no tax/discount model, so subtotal == total
     * for now; both columns exist so a future phase can add one
     * without a migration.
     */
    private function recomputeTotals(Invoice $invoice): void
    {
        $subtotal = (int) $invoice->lines()->sum('amount_cents');

        $invoice->update([
            'subtotal_cents' => $subtotal,
            'total_cents' => $subtotal,
        ]);
    }
}
