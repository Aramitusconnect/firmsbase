<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3J — continuing the staged "Phase 1 RLS Enforcement
 * Activation" gate beyond Section 39A-3I's conflict_check_runs.
 * Permanently activates FORCE ROW LEVEL SECURITY for the last of this
 * batch's four approved prepared tables: consultations.
 *
 * ConsultationFactory previously created its firm and its firm_lead as
 * two independent random Firm::factory()/FirmLead::factory() calls
 * (the same masked-blast-radius pattern MatterFactory/InvoiceFactory/
 * ConflictCheckRunFactory had before Sections 39A-3F/39A-3H/39A-3I) —
 * since FirmLead::factory() itself independently generates its own
 * separate firm, a bare Consultation::factory()->create() produced a
 * consultation whose firm_lead belonged to an unrelated firm. That
 * mismatch is fixed in this same batch (see ConsultationFactory's
 * rewritten definition(), which now generates one firm_lead first and
 * ties firm_id to that lead's own firm — mirroring the existing
 * forLead() state's already-correct logic) before this migration is
 * safe to apply.
 *
 * As established in every prior FORCE batch since Section 39A-3F,
 * PostgreSQL foreign-key constraint checks bypass row level security
 * entirely, so forcing consultations does not affect firm_leads
 * inserts/updates themselves. The two production call sites that read
 * from consultations/firm_leads outside of an existing tenant context
 * (FirmCommandCenterAggregationService::snapshot()'s newLeadsCount and
 * consultationsCount metrics) are wired alongside this migration.
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'consultations';

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
