<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L, Checkpoint 17, Table Phase B — permanently activates
 * FORCE ROW LEVEL SECURITY for document_chase_events.
 *
 * Investigation converged: firm_id is NOT NULL, direct ownership,
 * standard policy (document_chase_events_tenant_isolation — FOR ALL
 * USING firm_id = NULLIF(current_setting('app.current_firm_id', true),
 * '')::bigint, created by this repo's Phase 4 preparation migration) —
 * unchanged by this migration. No unrelated table's schema needed to
 * change. document_chase_rules (a sibling table in the same family)
 * was already forced by Section 39A-3K, before this mission began.
 *
 * The sole production write path, DocumentChaseService (checkAndLog(),
 * escalate(), pause(), resume(), and their shared private logEvent()
 * helper), was ALREADY fully tenant-context-wrapped as of Section
 * 39A-3L, Checkpoint 10 — that checkpoint anticipated forcing this
 * table later and wired the wraps at each public method's own call
 * site (never inside logEvent() itself, avoiding a nested-wrap: a
 * naive wrap inside logEvent() would let its own finally-block clear
 * the outer caller's still-active context prematurely). No further
 * production change was needed there.
 *
 * A production fix WAS needed elsewhere: FirmCommandCenterAggregationService::
 * snapshot()'s documentChaseEscalationsCount field ran a bare, unwrapped
 * DocumentChaseEvent::query() — inconsistent with every sibling field in
 * the same method (all of which already wrap their own query in
 * runWithFirmContext($firm, ...), one independent wrap per field,
 * evaluated as separate constructor arguments to CommandCenterSnapshot,
 * not nested). Wrapped to match the established pattern.
 *
 * DocumentChaseEventFactory's bare definition() was also fixed (same
 * bug class as Checkpoints 5/7/8/10/12/13/14/15/16): firm_id and
 * document_request_item_id used to resolve via two independent random
 * factory chains, which could produce a cross-firm mismatch. This
 * table's own firm_id column is direct, but document_request_item_id's
 * owning firm is only reachable through document_request_items ->
 * document_requests.firm_id (document_request_items itself carries no
 * firm_id of its own, per Checkpoint 10's own docblock). definition()
 * now creates one authoritative DocumentRequestItem up front and
 * derives firm_id from its own document_request's firm_id, matching
 * the already-correct forItem() state helper. The factory's create()
 * override was also given the same context-hold pattern used by every
 * FORCE-RLS factory since 39A-3A.
 *
 * Known, explicitly NOT fixed in this batch (tracked separately, same
 * accepted residual pattern as every other table in this mission): no
 * composite foreign key validates that document_request_item_id's
 * owning firm matches document_chase_events.firm_id. FORCE RLS does
 * not catch this (RLS only checks this table's own firm_id column,
 * never a related row's firm_id), so a cross-firm
 * document_request_item_id reference remains theoretically possible at
 * the database layer if application code ever bypassed the established
 * write path.
 *
 * Not covered by RowLevelSecurityPreparationTest.php or
 * Phase6RowLevelSecurityTest.php's exception-list mechanisms (confirmed
 * via direct search — neither file's own table set includes this one)
 * — this is a Phase 4 table, covered instead by the standard
 * RlsForceRolloutFirewallTest.php mechanism (and its sibling firewall
 * tests), same as every other table forced by this mission.
 *
 * As with every prior batch in this arc, the down() migration restores
 * only the RLS-enabled-but-not-forced baseline for this one table — it
 * never drops the existing policy or disables RLS itself.
 */
return new class extends Migration
{
    private const TABLE = 'document_chase_events';

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
