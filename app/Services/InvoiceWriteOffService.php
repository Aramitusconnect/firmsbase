<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Invoice;
use App\Models\InvoiceWriteOff;
use Illuminate\Support\Facades\DB;

/**
 * InvoiceWriteOffService — Phase G. The only writer of invoice_write_offs
 * and the only service that may move an Invoice's status to WrittenOff.
 * Writes off exactly the invoice's remaining UNPAID balance
 * (total_cents - amount_paid_cents); the already-paid portion is
 * untouched (a firm that also wants to give that money back uses
 * OperatingPaymentRefundService against the specific payment instead —
 * a write-off is "we will never collect the rest," not a refund of
 * money already received).
 *
 * No accounting journal entry is posted (see the invoice_write_offs
 * migration's own docblock: under this codebase's payment-time
 * revenue-recognition model, the unpaid remainder was never on the
 * operating books in the first place, so there is nothing to reverse).
 */
class InvoiceWriteOffService
{
    public function writeOff(Firm $firm, Invoice $invoice, string $reason, ?FirmUser $writtenOffBy = null): Invoice
    {
        if ((int) $invoice->firm_id !== (int) $firm->id) {
            throw new \RuntimeException('This invoice does not belong to this firm.');
        }

        if (in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Void, InvoiceStatus::Paid, InvoiceStatus::Refunded, InvoiceStatus::WrittenOff], true)) {
            throw new \RuntimeException('This invoice has no remaining collectible balance to write off from its current status.');
        }

        $remainingCents = $invoice->total_cents - $invoice->amount_paid_cents;

        if ($remainingCents <= 0) {
            throw new \RuntimeException('This invoice has no remaining unpaid balance to write off.');
        }

        return (new TenantContextService)->runWithFirmContext($firm, fn () => DB::transaction(function () use ($firm, $invoice, $reason, $writtenOffBy, $remainingCents) {
            InvoiceWriteOff::create([
                'firm_id' => $firm->id,
                'invoice_id' => $invoice->id,
                'amount_cents' => $remainingCents,
                'reason' => $reason,
                'actor_firm_user_id' => $writtenOffBy?->id,
                'created_at' => now(),
            ]);

            $invoice->update(['status' => InvoiceStatus::WrittenOff]);

            return $invoice->fresh();
        }));
    }
}
