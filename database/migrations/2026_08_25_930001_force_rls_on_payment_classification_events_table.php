<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L, Checkpoint 1, Table Phase B — permanently activates
 * FORCE ROW LEVEL SECURITY for exactly one additional prepared table:
 * payment_classification_events.
 *
 * PaymentClassificationEventFactory previously created its bare
 * definition() from two independent random Firm::factory()/
 * Payment::factory() calls (the same masked-blast-radius pattern
 * PaymentFactory itself had before Section 39A-3H), so a bare
 * PaymentClassificationEvent::factory()->create() produced an event
 * whose payment belonged to an unrelated firm. That mismatch is fixed
 * in this same batch (see PaymentClassificationEventFactory's
 * rewritten definition(), which now generates one payment and ties the
 * event's firm_id to that payment's own firm) before this migration is
 * safe to apply.
 *
 * The only production write path, PaymentClassificationService::
 * recordDecision(), is already correctly protected: it is deliberately
 * NOT self-wrapped in runWithFirmContext() because both of its callers
 * (ManualPaymentService::submit(), TrustTransferRequestService::
 * apply()) already establish firm context around the call — no
 * service code change is required or made by this migration.
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'payment_classification_events';

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
