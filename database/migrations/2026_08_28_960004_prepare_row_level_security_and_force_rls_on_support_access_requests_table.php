<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * support_access_requests — fourth of a six-table, one-batch FORCE ROW
 * LEVEL SECURITY activation covering the governance/support/platform
 * domain (Section 39A-8 Wave 8). See 2026_08_28_960001's docblock for
 * the full batch rationale and table order. Must land BEFORE
 * support_access_sessions (2026_08_28_960005) — a hard, parent-before-
 * child ordering requirement, since 960005 adds a composite foreign key
 * that references the UNIQUE(firm_id, id) constraint this migration
 * adds below.
 *
 * support_access_requests has NO pre-existing policy to flip FORCE on
 * for — this migration does all three steps (ENABLE, CREATE POLICY,
 * FORCE) in one batch, never leaving RLS-enabled-with-no-policy as an
 * intermediate state.
 *
 * Table selection rationale: support_access_requests carries a direct,
 * NOT NULL firm_id column (the TARGET firm), cascadeOnDelete() (see
 * database/migrations/2026_07_10_900015_create_support_access_requests_
 * table.php:20). Neither SupportAccessRequest nor SupportAccessSession
 * uses BelongsToTenant — deliberate: the actor is always a
 * PlatformAdmin with no ambient firm-membership context
 * (AdminPanelProvider wires zero tenant-context middleware). firm_id is
 * still the correct, sufficient isolation key for RLS purposes — RLS
 * operates at the DB layer regardless of Eloquent trait usage. Fully
 * mutable via SupportAccessRequestService::approve()/deny()/expire() —
 * combined, symmetric, FOR ALL command shape, matching the canonical
 * template used throughout this rollout.
 *
 * A compound UNIQUE(firm_id, id) constraint is added first (before
 * ENABLE/POLICY/FORCE) so that support_access_sessions
 * (2026_08_28_960005) can add a real composite foreign key —
 * (firm_id, support_access_request_id) REFERENCES
 * support_access_requests(firm_id, id) — the first table in this
 * rollout wave where the cross-firm-mismatch risk (a session whose
 * firm_id disagrees with its own parent request's firm_id) is closed at
 * the database layer rather than merely documented as an accepted gap.
 * PostgreSQL requires the referenced column set to be covered by a
 * UNIQUE or PRIMARY KEY constraint before it can be referenced by a
 * composite foreign key — id alone is already unique (primary key), but
 * the pair (firm_id, id) is not, hence this additional constraint.
 *
 * Known, deliberately-deferred gaps (not closed by this migration):
 *   1. PostgreSQL's documented row-security semantics exempt foreign-
 *      key ON DELETE CASCADE actions from row-security policy
 *      evaluation entirely — deleting a firms row will always
 *      cascade-delete dependent support_access_requests rows regardless
 *      of which tenant's context is currently active. Expected,
 *      identical behavior to every other cascade-on-firms table already
 *      forced in this repository.
 *   2. No current code path implements a cross-firm admin screen (e.g.
 *      "list all pending requests across every firm") — deliberately
 *      not built speculatively in this batch. If/when built, must be
 *      designed against its actual read pattern (e.g. an explicit
 *      per-firm loop calling runWithFirmContext() once per firm,
 *      aggregating in PHP), never a widened RLS policy.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'support_access_requests';

    private const POLICY = 'support_access_requests_tenant_isolation';

    private const FIRM_ID_UNIQUE_CONSTRAINT = 'support_access_requests_firm_id_id_unique';

    public function up(): void
    {
        $table = $this->quoteIdentifier(self::TABLE);
        $policy = $this->quoteIdentifier(self::POLICY);
        $uniqueConstraint = $this->quoteIdentifier(self::FIRM_ID_UNIQUE_CONSTRAINT);

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$uniqueConstraint} UNIQUE (firm_id, id)");

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
     * remove all effects up() added, in reverse order: FORCE, the
     * policy, row-level security being enabled at all, and finally the
     * compound UNIQUE(firm_id, id) constraint — restoring the table to
     * its true pre-this-migration (MISSING_PREPARED_TABLES) state. By
     * the time this down() runs during a rollback, support_access_
     * sessions' own composite foreign key (2026_08_28_960005) has
     * already been dropped by that migration's down() running first
     * (Laravel rolls back in reverse chronological order), so dropping
     * this constraint here never fails on a still-dependent FK.
     */
    public function down(): void
    {
        $table = $this->quoteIdentifier(self::TABLE);
        $policy = $this->quoteIdentifier(self::POLICY);
        $uniqueConstraint = $this->quoteIdentifier(self::FIRM_ID_UNIQUE_CONSTRAINT);

        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY {$policy} ON {$table}");
        DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$uniqueConstraint}");
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $identifier)) {
            throw new RuntimeException("Refusing to operate on an unsafe/unexpected identifier: {$identifier}");
        }

        return '"'.$identifier.'"';
    }
};
