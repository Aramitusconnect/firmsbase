<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L, Checkpoint 14, Table Phase B — permanently activates
 * FORCE ROW LEVEL SECURITY for matter_readiness_scores.
 *
 * Recovery note: this checkpoint's WIP was originally drafted together
 * with a second, follow-on table (readiness_score_events, the paired
 * Checkpoint 15 migration 2026_08_25_930015) under a single combined
 * "Checkpoints 14/15" label, on the premise that both writes live in
 * the same MatterReadinessService::recompute() method. On reconciliation
 * this premise did not hold up under inspection: the two writes are two
 * independent statements inside one method, not one atomic operation
 * that requires a single shared tenant-context boundary, and
 * readiness_score_events does not need to be forced for this table's
 * own correctness. The governing rule for this mission is one table per
 * checkpoint, one reviewed commit per table — so the two were split.
 * See the Checkpoint 15 migration's own docblock for readiness_score_events.
 *
 * All three Phase A audits (rls-inventory-analyst, tenant-context-
 * auditor, security-reviewer — rls-policy-designer not used, since the
 * policy already exists) converged: firm_id is NOT NULL, direct
 * ownership, standard policy (matter_readiness_scores_tenant_isolation
 * — FOR ALL USING firm_id = NULLIF(current_setting('app.current_firm_id',
 * true), '')::bigint, created by the Phase 4 preparation migration
 * 2026_07_07_800016_extend_row_level_security_to_phase_4_tenant_tables)
 * — unchanged by this migration. No unrelated table's schema needed to
 * change.
 *
 * A production fix WAS needed: MatterReadinessService::recompute() had
 * a "decoy wrap" — it wrapped only a throwaway no-op read while the
 * real persistence (matter_readiness_scores' own fill()->save()) ran
 * completely unwrapped. Naively wrapping the whole recompute() body was
 * tried first and empirically FAILED: registry->evaluate($matter)
 * invokes two evaluators (documents_approved, tasks_dependencies_ready)
 * that used to self-wrap their own queries in runWithFirmContext() —
 * individually correct in isolation, but each one's own finally-block
 * teardown cleared this method's OUTER context before the write below
 * ran. The fix removes both evaluators' internal self-wraps
 * (ReadinessScorecardRegistry now shifts that responsibility to the
 * caller — this fix belongs to this checkpoint alone, since neither
 * evaluator queries matter_readiness_scores or readiness_score_events;
 * they query document_request_items and tasks) and replaces
 * recompute()'s decoy wrap with a single real wrap around the
 * evaluate()-through-fresh() sequence for the score itself, including
 * the firstOrNew() score lookup (under FORCE, an unscoped SELECT with
 * no context returns zero rows even if a row exists, which would
 * otherwise make firstOrNew() attempt a duplicate INSERT against the
 * matter_id unique constraint). The subsequent readiness_score_events
 * write and the DB::afterCommit() webhook-scheduling block both remain
 * OUTSIDE this wrap, unchanged from before this checkpoint — the event
 * write does not yet need tenant context because readiness_score_events
 * is not yet FORCE RLS (that incremental wrap is Checkpoint 15's own
 * job), and moving afterCommit() inside would defer firing to
 * runWithFirmContext()'s own internal transaction commit instead of
 * firing immediately, which is unrelated to this checkpoint either way.
 *
 * MatterReadinessScoreFactory's bare definition() was also fixed (same
 * bug class as Checkpoints 5/7/8/10/12/13): firm_id and matter_id used
 * to resolve via two independent random factory chains, which could
 * produce a cross-firm mismatch. definition() now creates one
 * authoritative Matter up front and derives both firm_id and matter_id
 * from it, matching the already-correct forMatter() state helper. The
 * factory's create() override was also given the same context-hold
 * pattern used by every FORCE-RLS factory since 39A-3A.
 * ReadinessScoreEventFactory has the same known bug but is untouched
 * here — deferred to Checkpoint 15, since it is not required for this
 * table's own correctness.
 *
 * Known, explicitly NOT fixed in this batch (tracked separately, same
 * accepted residual pattern as every other table in this mission): no
 * composite foreign key validates that matter_id's owning firm matches
 * matter_readiness_scores.firm_id. FORCE RLS does not catch this (RLS
 * only checks this table's own firm_id column, never a related row's
 * firm_id), so a cross-firm matter_id reference remains theoretically
 * possible at the database layer if application code ever bypassed the
 * established write path.
 *
 * Not covered by RowLevelSecurityPreparationTest.php or
 * Phase6RowLevelSecurityTest.php's exception-list mechanisms — this is
 * a Phase 4 table, covered instead by the standard
 * RlsForceRolloutFirewallTest.php mechanism (and its sibling firewall
 * tests), same as every other table forced by this mission.
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'matter_readiness_scores';

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
