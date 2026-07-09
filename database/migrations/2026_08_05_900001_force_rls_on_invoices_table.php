<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3G — seventh batch of the staged "Phase 1 RLS
 * Enforcement Activation" gate. Permanently activates FORCE ROW LEVEL
 * SECURITY for exactly one additional prepared table: invoices.
 *
 * InvoiceFactory previously created its firm and its client as two
 * independent random Firm::factory()/Client::factory() calls (the same
 * masked-blast-radius pattern MatterFactory had before Section
 * 39A-3F), so a bare Invoice::factory()->create() produced an invoice
 * whose client belonged to an unrelated firm. That mismatch is fixed
 * in this same batch (see InvoiceFactory's rewritten definition(),
 * which now generates one firm and ties both the invoice and its
 * nested client to it) before this migration is safe to apply.
 *
 * As established in Section 39A-3F, PostgreSQL foreign-key constraint
 * checks bypass row level security entirely, so forcing invoices does
 * NOT break child-table inserts (payments.invoice_id,
 * payment_plans.invoice_id, invoice_lines.invoice_id, etc.) — only
 * direct reads/writes/deletes against the invoices table itself are
 * affected, and those real call sites are fixed alongside this
 * migration (InvoiceDraftingService, ImportApplyService,
 * ManualPaymentService, PaymentApplicationService,
 * TrustTransferRequestService, AccountingExportLineBuilderService,
 * FirmCommandCenterAggregationService).
 *
 * payments remains deferred: its factory still nests Client::factory()
 * directly inside definition(), which means its true insert-time
 * blast radius stays masked/unproven until that cascade is explicitly
 * fixed in a dedicated later batch (39A-3H).
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'invoices';

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
