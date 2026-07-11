<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L, Checkpoint 3, Table Phase B — permanently activates
 * FORCE ROW LEVEL SECURITY for exactly one additional prepared table:
 * firm_activation_events.
 *
 * Four independent audits (rls-inventory-analyst, tenant-context-
 * auditor, rls-policy-designer, security-reviewer) confirmed: firm_id
 * is NOT NULL, direct ownership, genuinely append-only (the model sets
 * UPDATED_AT = null and zero update()/delete() call sites exist against
 * it anywhere), and the existing policy is byte-for-byte identical in
 * shape to every already-forced precedent table's policy (FOR ALL USING
 * firm_id = NULLIF(current_setting('app.current_firm_id', true), '')::bigint,
 * no explicit WITH CHECK) — unchanged by this migration.
 *
 * FirmProductionActivationService (the only writer of
 * firm_activation_events) is wrapped in this same batch (see that
 * file's diff): evaluate()'s previously-unwrapped, redundant
 * loadMissing() call was deleted outright (it was poisoning the
 * relation cache ahead of ActivationChecklistService::
 * unmetRequirements()'s own correct, wrapped load); recordEvaluation()
 * and autoCompleteVerifiableItems() are each given their own whole-
 * method runWithFirmContext() wrap. FirmActivationEventFactory is given
 * the standard context-hold create() override so bare
 * FirmActivationEvent::factory()->create() calls keep working once
 * FORCE lands.
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'firm_activation_events';

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
