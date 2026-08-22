<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * support_access_sessions — fifth of a six-table, one-batch FORCE ROW
 * LEVEL SECURITY activation covering the governance/support/platform
 * domain (Section 39A-8 Wave 8). See 2026_08_28_960001's docblock for
 * the full batch rationale and table order. Lands strictly after
 * support_access_requests (2026_08_28_960004) — a hard, parent-before-
 * child ordering requirement: the composite foreign key this migration
 * adds below requires support_access_requests' own
 * UNIQUE(firm_id, id) constraint (added by 960004) to already exist.
 *
 * support_access_sessions has NO pre-existing policy to flip FORCE on
 * for — this migration does all three steps (ENABLE, CREATE POLICY,
 * FORCE) in one batch, never leaving RLS-enabled-with-no-policy as an
 * intermediate state.
 *
 * Table selection rationale: support_access_sessions carries a direct,
 * NOT NULL firm_id column, denormalized straight from the parent
 * support_access_requests row at creation time
 * (SupportAccessSessionService::start()), cascadeOnDelete() (see
 * database/migrations/2026_07_10_900016_create_support_access_sessions_
 * table.php:20). Like support_access_requests, the SupportAccessSession
 * model does NOT use BelongsToTenant (actor is always a PlatformAdmin,
 * no ambient firm-membership context) — firm_id is still the correct,
 * sufficient isolation key; RLS operates at the DB layer regardless of
 * Eloquent trait usage. Fully mutable via
 * SupportAccessSessionService::end()/revoke() — combined, symmetric,
 * FOR ALL command shape, matching the canonical template used
 * throughout this rollout.
 *
 * A real composite foreign key is added here — the best candidate in
 * this wave for closing a cross-firm-mismatch gap at the database layer
 * rather than merely documenting it: (firm_id, support_access_request_id)
 * REFERENCES support_access_requests(firm_id, id). Before this
 * migration, nothing in the database enforced that this row's own
 * firm_id agreed with its parent support_access_request_id's own
 * firm_id — only SupportAccessSessionService::start()'s direct copy
 * ('firm_id' => $request->firm_id) enforced agreement, application-layer
 * only. This is ADDITIVE to the pre-existing single-column foreign key
 * on support_access_request_id alone (added by the original create-table
 * migration, unchanged) — both constraints coexist; the composite FK
 * does not replace or narrow the existing one. ON DELETE CASCADE
 * mirrors the existing single-column FK's own cascadeOnDelete()
 * behavior for this same parent-child relationship.
 *
 * Known, deliberately-deferred gaps (not closed by this migration):
 *   1. PostgreSQL's documented row-security semantics exempt foreign-
 *      key ON DELETE CASCADE actions from row-security policy
 *      evaluation entirely — deleting a firms row will always
 *      cascade-delete dependent support_access_sessions rows regardless
 *      of which tenant's context is currently active. Expected,
 *      identical behavior to every other cascade-on-firms table already
 *      forced in this repository.
 *   2. No current code path implements a cross-firm admin screen — see
 *      960004's own docblock; same deliberate deferral applies here.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'support_access_sessions';

    private const PARENT_TABLE = 'support_access_requests';

    private const POLICY = 'support_access_sessions_tenant_isolation';

    private const COMPOSITE_FK = 'support_access_sessions_firm_id_request_id_foreign';

    public function up(): void
    {
        $table = $this->quoteIdentifier(self::TABLE);
        $parentTable = $this->quoteIdentifier(self::PARENT_TABLE);
        $policy = $this->quoteIdentifier(self::POLICY);
        $compositeFk = $this->quoteIdentifier(self::COMPOSITE_FK);

        DB::statement(
            "ALTER TABLE {$table} ADD CONSTRAINT {$compositeFk} "
            .'FOREIGN KEY (firm_id, support_access_request_id) '
            ."REFERENCES {$parentTable} (firm_id, id) ON DELETE CASCADE"
        );

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
     * Full rollback, in reverse order of up(): NO FORCE, DROP POLICY,
     * DISABLE, then drop the composite foreign key this migration
     * added — restoring the table to its true pre-this-migration
     * (MISSING_PREPARED_TABLES, no composite FK) state. The composite
     * FK is dropped here (in this migration's own down()), before
     * 960004's down() drops the UNIQUE(firm_id, id) constraint it
     * depends on, which is exactly the correct order since Laravel
     * rolls back migrations in reverse chronological order (this one,
     * 960005, runs before 960004 during a rollback).
     */
    public function down(): void
    {
        $table = $this->quoteIdentifier(self::TABLE);
        $policy = $this->quoteIdentifier(self::POLICY);
        $compositeFk = $this->quoteIdentifier(self::COMPOSITE_FK);

        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY {$policy} ON {$table}");
        DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$compositeFk}");
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $identifier)) {
            throw new RuntimeException("Refusing to operate on an unsafe/unexpected identifier: {$identifier}");
        }

        return '"'.$identifier.'"';
    }
};
