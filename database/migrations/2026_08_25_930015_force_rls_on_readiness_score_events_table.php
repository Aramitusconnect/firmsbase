<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L, Checkpoint 15, Table Phase B — permanently activates
 * FORCE ROW LEVEL SECURITY for readiness_score_events, the append-only
 * audit table written alongside matter_readiness_scores by
 * MatterReadinessService::recompute() (Checkpoint 14's own migration,
 * 2026_08_25_930014).
 *
 * Recovery note: this checkpoint's WIP was originally drafted together
 * with matter_readiness_scores under a single combined "Checkpoints
 * 14/15" label. On reconciliation the two tables were split into one
 * checkpoint each — see Checkpoint 14's migration docblock for the
 * full separability analysis. This checkpoint is the incremental
 * follow-on: it assumes matter_readiness_scores is already forced and
 * adds exactly the change readiness_score_events itself needs.
 *
 * All three Phase A audits converged: firm_id is NOT NULL, direct
 * ownership, standard policy (readiness_score_events_tenant_isolation
 * — FOR ALL USING firm_id = NULLIF(current_setting('app.current_firm_id',
 * true), '')::bigint, created by the same Phase 4 preparation migration
 * as matter_readiness_scores) — unchanged by this migration. No
 * unrelated table's schema needed to change.
 *
 * A production fix WAS needed, incremental to Checkpoint 14's own
 * change: MatterReadinessService::recompute() already wraps the score
 * read/write in one runWithFirmContext() call, but left the
 * ReadinessScoreEvent::create() call unwrapped afterward (correct at
 * the time, since readiness_score_events wasn't forced yet). Now that
 * this migration forces it, that create() call is moved inside the
 * SAME wrap — the closure's return tuple already carried
 * $satisfiedCount/$totalCount out for the metadata_json payload, so
 * this checkpoint threads the event write into the existing closure
 * body instead of adding a second wrap. The DB::afterCommit()
 * webhook-scheduling block remains outside the wrap, unchanged, for
 * the same reason described in Checkpoint 14's migration (moving it
 * inside would defer firing to the wrap's own internal transaction
 * commit instead of firing immediately).
 *
 * ReadinessScoreEventFactory's bare definition() was also fixed (same
 * bug class as Checkpoints 5/7/8/10/12/13/14): firm_id and matter_id
 * used to resolve via two independent random factory chains, which
 * could produce a cross-firm mismatch. definition() now creates one
 * authoritative Matter up front and derives both firm_id and matter_id
 * from it, matching the already-correct forMatter() state helper. The
 * factory's create() override was also given the same context-hold
 * pattern used by every FORCE-RLS factory since 39A-3A.
 *
 * Known, explicitly NOT fixed in this batch (tracked separately, same
 * accepted residual pattern as every other table in this mission): no
 * composite foreign key validates that matter_id's owning firm matches
 * readiness_score_events.firm_id. FORCE RLS does not catch this (RLS
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
    private const TABLE = 'readiness_score_events';

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
