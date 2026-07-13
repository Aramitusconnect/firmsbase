<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L, Checkpoint 2, Table Phase B — permanently activates
 * FORCE ROW LEVEL SECURITY for exactly one additional prepared table:
 * activation_checklists.
 *
 * Four independent audits (rls-inventory-analyst, tenant-context-
 * auditor, rls-policy-designer, security-reviewer) confirmed: firm_id
 * is NOT NULL and UNIQUE (one checklist per firm), direct ownership,
 * and the existing policy is byte-for-byte identical in shape to
 * every already-forced precedent table's policy (FOR ALL USING
 * firm_id = NULLIF(current_setting('app.current_firm_id', true), '')::bigint,
 * no explicit WITH CHECK) — unchanged by this migration.
 *
 * The child table activation_checklist_items deliberately has no
 * firm_id column of its own (scoped transitively via
 * activation_checklist_id) and is NOT touched by this migration — it
 * was never a FORCE RLS candidate and remains excluded.
 *
 * All five ActivationChecklistService methods that touch
 * activation_checklists (unmetRequirements(), isEligible(), activate(),
 * createChecklist(), seedProductionReadinessItems()) are wrapped in
 * runWithFirmContext() in this same batch (see that file's diff), and
 * ActivationChecklistFactory is given the standard context-hold
 * create() override so bare ActivationChecklist::factory()->create()
 * calls keep working once FORCE lands.
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'activation_checklists';

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
