<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * deletion_requests — second of a six-table, one-batch FORCE ROW LEVEL
 * SECURITY activation covering the governance/support/platform domain
 * (Section 39A-8 Wave 8). See 2026_08_28_960001's docblock for the full
 * batch rationale and table order. Lands after legal_holds because
 * DeletionGovernanceService::checkClearance() calls
 * LegalHoldService::hasActiveHold() as part of its own clearance gate.
 *
 * deletion_requests has NO pre-existing policy to flip FORCE on for —
 * this migration does all three steps (ENABLE, CREATE POLICY, FORCE) in
 * one batch, never leaving RLS-enabled-with-no-policy as an
 * intermediate state.
 *
 * Table selection rationale: deletion_requests carries a direct, NOT
 * NULL firm_id column, cascadeOnDelete() (see database/migrations/
 * 2026_07_28_900007_create_deletion_requests_table.php:23). The
 * DeletionRequest model uses BelongsToTenant + HasPublicUuid — a
 * genuine tenant-owned row. booted() blocks physical deletion of this
 * row (permanent governance evidence) but the row is otherwise fully
 * mutable via update() (status transitions through the approval
 * lifecycle) — combined, symmetric, FOR ALL command shape, matching the
 * canonical template used throughout this rollout.
 *
 * Known, deliberately-deferred gaps (not closed by this migration):
 *   1. subject_type/subject_id is a genuinely polymorphic pair by
 *      design (approved decision #9 — deletion governance may target
 *      many record types over time) — cannot be expressed as a
 *      composite foreign key at all. No DB-level invariant can verify
 *      the referenced subject's own firm_id matches this row's firm_id;
 *      only DeletionRequestService::request()'s caller-supplied Firm is
 *      trusted, with no explicit cross-firm assertion of the subject
 *      performed today. Documented as a known, accepted gap, same
 *      posture as expense_categories's deferred gap, since no DB-level
 *      invariant can express it.
 *   2. offboarding_export_id (nullable, nullOnDelete()) is a single-hop
 *      foreign key with no composite FK or trigger tying
 *      offboarding_exports.firm_id to this row's own firm_id — same
 *      class of accepted, residual application-layer gap.
 *   3. PostgreSQL's documented row-security semantics exempt foreign-
 *      key ON DELETE CASCADE actions from row-security policy
 *      evaluation entirely — deleting a firms row will always
 *      cascade-delete dependent deletion_requests rows regardless of
 *      which tenant's context is currently active. Expected, identical
 *      behavior to every other cascade-on-firms table already forced in
 *      this repository.
 *   4. deletion_approvals (the linked approval row for this request)
 *      has no firm_id column of its own at all and is architecturally
 *      excluded from the standard firm_id RLS template entirely — a
 *      separate, unaddressed design question, out of scope for this
 *      migration. See DeletionApprovalService::secondApprove()/deny()'s
 *      own production-service fix in this same batch for how the
 *      resulting bootstrap gap on THIS table was closed (an explicit
 *      $request parameter, not a lazy relation load).
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'deletion_requests';

    private const POLICY = 'deletion_requests_tenant_isolation';

    public function up(): void
    {
        $table = $this->quoteIdentifier(self::TABLE);
        $policy = $this->quoteIdentifier(self::POLICY);

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");

        DB::statement(<<<SQL
            CREATE POLICY {$policy}
            ON {$table}
            USING (
                firm_id = NULLIF(current_setting('app.current_firm_id', true), '')::bigint
            )
            WITH CHECK (
                firm_id = NULLIF(current_setting('app.current_firm_id', true), '')::bigint
            )
        SQL);

        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
    }

    /**
     * Full rollback: this migration introduced the policy itself (there
     * was no pre-existing policy to merely un-FORCE), so down() must
     * remove all three effects up() added: FORCE, the policy, and row-
     * level security being enabled at all — restoring the table to its
     * true pre-this-migration (MISSING_PREPARED_TABLES) state.
     */
    public function down(): void
    {
        $table = $this->quoteIdentifier(self::TABLE);
        $policy = $this->quoteIdentifier(self::POLICY);

        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY {$policy} ON {$table}");
        DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $identifier)) {
            throw new RuntimeException("Refusing to operate on an unsafe/unexpected identifier: {$identifier}");
        }

        return '"'.$identifier.'"';
    }
};
