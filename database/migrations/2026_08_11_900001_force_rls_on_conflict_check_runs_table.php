<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3I — the first HIGH-risk-tier batch of the staged "Phase
 * 1 RLS Enforcement Activation" gate, continuing beyond the seven
 * originally pilot-critical tables (clients, firm_users, documents,
 * deadlines, tasks, matters, invoices, payments) plus payments'
 * follow-up self-lookup fix. Permanently activates FORCE ROW LEVEL
 * SECURITY for exactly one additional prepared table: conflict_check_runs.
 *
 * conflict_check_runs holds prospective-client/opposing-party/witness
 * search data captured BEFORE a person may become a formal client —
 * cross-firm exposure here is a genuine confidentiality and
 * professional-responsibility risk, which is why this table was
 * prioritized ahead of the remaining 43 prepared-but-unforced tables.
 *
 * ConflictCheckRunFactory previously created its firm and its matter as
 * two independent random Firm::factory()/Matter::factory() calls (the
 * same masked-blast-radius pattern MatterFactory/InvoiceFactory/
 * PaymentFactory had before Sections 39A-3F/39A-3G/39A-3H) — since
 * Matter::factory() itself internally generates its own separate firm,
 * a bare ConflictCheckRun::factory()->create() produced a run whose
 * matter belonged to an unrelated firm. That mismatch is fixed in this
 * same batch (see ConflictCheckRunFactory's rewritten definition(),
 * which now generates one matter first and ties both the run and its
 * firm_id to that matter's own firm) before this migration is safe to
 * apply.
 *
 * As established in every prior FORCE batch since Section 39A-3F,
 * PostgreSQL foreign-key constraint checks bypass row level security
 * entirely, so forcing conflict_check_runs does NOT break the
 * conflict_check_results child table's inserts (it has no firm_id of
 * its own — scoped transitively via conflict_check_run_id — and is not
 * itself a FORCE-RLS candidate). Only direct reads/writes against
 * conflict_check_runs itself are affected, and the one real call site
 * (ConflictCheckService::run()) is fixed alongside this migration.
 *
 * Known, documented residual gap (same class as Matter/Client,
 * Invoice/Client/Matter, and Payment/Client/Matter/Invoice before it):
 * conflict_check_runs.matter_id is never cross-validated against
 * conflict_check_runs.firm_id by RLS — a raw insert with a correct
 * firm_id but a matter_id belonging to a different firm still
 * succeeds, since a single-column policy cannot see across a foreign
 * key. Mitigated at the factory level (forMatter() derives every
 * column from one consistent source), proven rather than assumed in
 * this batch's own activation test. A future composite/trigger-based
 * database constraint remains recommended but out of scope here.
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'conflict_check_runs';

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
