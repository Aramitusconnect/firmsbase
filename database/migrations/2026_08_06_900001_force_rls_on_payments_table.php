<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3H — eighth batch of the staged "Phase 1 RLS Enforcement
 * Activation" gate. Permanently activates FORCE ROW LEVEL SECURITY for
 * exactly one additional prepared table: payments.
 *
 * PaymentFactory previously created its firm and its client as two
 * independent random Firm::factory()/Client::factory() calls (the same
 * masked-blast-radius pattern MatterFactory/InvoiceFactory had before
 * Sections 39A-3F/39A-3G), so a bare Payment::factory()->create()
 * produced a payment whose client belonged to an unrelated firm. That
 * mismatch is fixed in this same batch (see PaymentFactory's rewritten
 * definition(), which now generates one firm and ties both the payment
 * and its nested client to it) before this migration is safe to apply.
 *
 * As established in Sections 39A-3F/39A-3G, PostgreSQL foreign-key
 * constraint checks bypass row level security entirely, so forcing
 * payments does NOT break child-table inserts (manual_payment_records
 * .payment_id, payment_classification_events.payment_id, etc.) — only
 * direct reads/writes/deletes against the payments table itself are
 * affected, and those real call sites are fixed alongside this
 * migration (ManualPaymentService, PaymentClassificationService,
 * TrustTransferRequestService, AccountingExportLineBuilderService,
 * FirmCommandCenterAggregationService).
 *
 * This is the last of the seven pilot-critical prepared tables named
 * in this staged arc (clients, firm_users, documents, deadlines,
 * tasks, matters, invoices, payments) — no further table is forced by
 * this migration or this batch.
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'payments';

    public function up(): void
    {
        DB::statement('ALTER TABLE '.$this->quoteIdentifier(self::TABLE).' FORCE ROW LEVEL SECURITY');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE '.$this->quoteIdentifier(self::TABLE).' NO FORCE ROW LEVEL SECURITY');
    }

    private function quoteIdentifier(string $table): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $table)) {
            throw new \RuntimeException("Refusing to activate FORCE RLS on an unsafe/unexpected identifier: {$table}");
        }

        return '"'.$table.'"';
    }
};
