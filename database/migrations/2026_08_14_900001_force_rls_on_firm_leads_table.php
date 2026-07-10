<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3J — continuing the staged "Phase 1 RLS Enforcement
 * Activation" gate beyond Section 39A-3I's conflict_check_runs.
 * Permanently activates FORCE ROW LEVEL SECURITY for one of this
 * batch's four approved prepared tables: firm_leads.
 *
 * FirmLeadFactory's definition() already ties every column to a
 * single generated firm (lead_source_id/practice_area_interest_id
 * default to null, so there is no independent nested tenant-owned
 * model to disagree with firm_id) — no factory logic changes were
 * required, only its context-hold create() retrofit (Section
 * 39A-3A/39A-3F pattern) so a bare FirmLead::factory()->create() can
 * still insert once FORCE is active. The one production call site
 * that creates a FirmLead outside of an existing tenant context
 * (ImportApplyService::createRecordFor()'s FirmLead branch) is wired
 * alongside this migration.
 *
 * Known, documented residual gap (same class as
 * conflict_check_runs.matter_id in Section 39A-3I):
 * firm_leads.converted_client_id is never cross-validated against
 * firm_leads.firm_id by RLS — a raw update setting a correct firm_id
 * row's converted_client_id to a client belonging to a different firm
 * still succeeds, since a single-column policy cannot see across a
 * foreign key and Postgres FK checks themselves bypass RLS entirely.
 * No composite constraint or trigger is added in this batch; this gap
 * is left open and documented, not hidden.
 *
 * As with every prior batch in this arc, the down() migration
 * restores only the RLS-enabled-but-not-forced baseline for this one
 * table — it never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'firm_leads';

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
