<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L, Checkpoint 12, Table Phase B — permanently activates
 * FORCE ROW LEVEL SECURITY for exactly one additional prepared table:
 * communication_consent_events.
 *
 * All three Phase A audits (rls-inventory-analyst, tenant-context-
 * auditor, security-reviewer — rls-policy-designer not used per an
 * established operational decision, since the policy already exists)
 * converged: firm_id is NOT NULL, direct ownership, standard policy
 * (communication_consent_events_tenant_isolation — FOR ALL USING
 * firm_id = NULLIF(current_setting('app.current_firm_id', true), '')
 * ::bigint, created by the Phase 1 preparation migration) — unchanged
 * by this migration. No unrelated table's schema needed to change.
 *
 * Unlike most prior checkpoints in this arc, no service-level fix was
 * needed for this checkpoint: Checkpoint 11 already correctly wrapped
 * this table's sole write path — ConsentService::capture()/revoke() —
 * in runWithFirmContext(), and the paired CommunicationConsentEvent::
 * create() call in each method body executes inside that same closure
 * as the CommunicationConsent write. This was verified empirically by
 * three independent Phase A audits before this migration was written,
 * per the reconciled Checkpoint 12 plan.
 *
 * Known, explicitly NOT fixed in this batch (tracked separately, same
 * accepted residual pattern as every other table in this mission): no
 * composite foreign key validates that communication_consent_id's
 * owning firm matches communication_consent_events.firm_id. FORCE RLS
 * does not catch this (RLS only checks this table's own firm_id column,
 * never a related row's firm_id), so a cross-firm
 * communication_consent_id reference remains theoretically possible at
 * the database layer if application code ever bypassed ConsentService.
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'communication_consent_events';

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
