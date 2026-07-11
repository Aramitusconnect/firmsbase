<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L, Checkpoint 5, Table Phase B — permanently activates
 * FORCE ROW LEVEL SECURITY for exactly one additional prepared table:
 * firm_entitlement_events.
 *
 * Four independent audits, reconciled by rls-coordinator: firm_id is
 * NOT NULL, direct ownership (denormalized alongside firm_entitlement_id),
 * standard policy (FOR ALL USING firm_id = current_setting(...)::bigint)
 * — unchanged by this migration. The table is genuinely append-only (no
 * updated_at column, model sets UPDATED_AT = null).
 *
 * The sole writer is EntitlementService::setForSource() (already fixed
 * in Checkpoint 4 — wraps both the FirmEntitlement write and the
 * FirmEntitlementEvent write in one runWithFirmContext() call inside
 * one transaction, using the same $firm object for both); it is not
 * touched again here.
 *
 * FirmEntitlementEventFactory's confirmed cross-firm mismatch bug
 * (definition() previously resolved firm_entitlement_id and firm_id via
 * two independent, unrelated Firm::factory() chains) is fixed in this
 * same batch, along with a context-hold create() override matching
 * FirmEntitlementFactory/FirmActivationEventFactory (see that file's
 * diff). DeploymentFeatureFlagAuditService's two remaining direct reads
 * against firm_entitlement_events (auditTrailFor() and
 * isFullyAudited()'s $eventCount query) are each given their own
 * whole-call runWithFirmContext() wrap in this same batch (see that
 * file's diff).
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'firm_entitlement_events';

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
