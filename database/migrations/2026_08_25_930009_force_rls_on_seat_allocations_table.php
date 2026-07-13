<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L, Checkpoint 9, Table Phase B — permanently activates
 * FORCE ROW LEVEL SECURITY for exactly one additional prepared table:
 * seat_allocations.
 *
 * Three Phase A audits (rls-inventory-analyst, tenant-context-auditor,
 * security-reviewer — rls-policy-designer declined, unrelated to this
 * table's actual readiness) converged precisely: firm_id is NOT NULL,
 * direct ownership, standard policy (FOR ALL USING firm_id =
 * NULLIF(current_setting('app.current_firm_id', true), '')::bigint) —
 * unchanged by this migration. seat_pool_id was confirmed to carry no
 * firm_id/RLS boundary of its own (seat_pools is organization-owned,
 * not firm-owned, and is deliberately exempt from Phase 6 RLS); no
 * unrelated table's schema or policy needed to change.
 *
 * SeatAllocationService's allocateDirect(), allocateFromPool(), and
 * revoke() are each fixed in this same batch to add a whole-method
 * TenantContextService::runWithFirmContext() wrap — all three methods
 * previously had zero tenant-context wrapping, confirmed by three
 * independent Phase A audits, and confirmed no nested-wrap/decoy-wrap
 * risk exists (nothing in this service calls another already-self-
 * wrapping method). DowngradeEvaluationService's evaluate() also gets a
 * narrow local wrap around its SeatEnforcementService::usageFor() call
 * site, matching this same batch's fix.
 *
 * SeatAllocationFactory's create() override adds the same context-hold
 * pattern as TemplateUpgradeLogFactory/InstalledTemplatePackFactory (see
 * those files' prior Checkpoint diffs) so a bare
 * SeatAllocation::factory()->create() keeps working once FORCE lands,
 * even though definition() itself carries no cross-firm mismatch today.
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'seat_allocations';

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
