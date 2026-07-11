<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L, Checkpoint 13, Table Phase B — permanently activates
 * FORCE ROW LEVEL SECURITY for exactly one additional prepared table:
 * intake_submissions.
 *
 * All three Phase A audits (rls-inventory-analyst, tenant-context-
 * auditor, security-reviewer — rls-policy-designer not used per an
 * established operational decision, since the policy already exists)
 * converged: firm_id is NOT NULL, direct ownership, standard policy
 * (intake_submissions_tenant_isolation — FOR ALL USING firm_id =
 * NULLIF(current_setting('app.current_firm_id', true), '')::bigint,
 * created by the Phase 2 preparation migration) — unchanged by this
 * migration. No unrelated table's schema needed to change.
 *
 * A production fix WAS needed for this checkpoint:
 * ProductionPilotWorkflowService::submitIntake() had zero tenant-
 * context wrapping. It is now wrapped, in full (the IntakeSubmission::
 * create() call, the subsequent update() call, and the fresh() reload),
 * in a single runWithFirmContext() call — a partial wrap (create() only)
 * was empirically confirmed to fail: update() would silently no-op (0
 * rows affected, no exception) once FORCE RLS is active without an open
 * context, so fresh() would then return null, causing a TypeError
 * against submitIntake()'s non-nullable IntakeSubmission return type.
 *
 * IntakeSubmissionFactory's bare definition() was also fixed (same bug
 * class as Checkpoints 5/7/8/10/12): firm_id and client_id used to
 * resolve via two independent random factory chains, which could
 * produce a cross-firm mismatch. definition() now creates one
 * authoritative Client up front and derives both firm_id and client_id
 * from it, matching the already-correct forClient() state helper. The
 * factory's create() override was also given the same context-hold
 * pattern used by every FORCE-RLS factory since 39A-3A.
 *
 * Known, explicitly NOT fixed in this batch (tracked separately, same
 * accepted residual pattern as every other table in this mission): no
 * composite foreign key validates that client_id's or matter_id's
 * owning firm matches intake_submissions.firm_id. FORCE RLS does not
 * catch this (RLS only checks this table's own firm_id column, never a
 * related row's firm_id), so a cross-firm client_id or matter_id
 * reference remains theoretically possible at the database layer if
 * application code ever bypassed the established write path.
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'intake_submissions';

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
