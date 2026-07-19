<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * export_jobs — first of a six-table, one-batch FORCE ROW LEVEL
 * SECURITY activation covering the migration/export domain
 * (Section 39A-9 Wave 9): export_jobs (this migration),
 * migration_projects (2026_08_29_970002), import_batches
 * (2026_08_29_970003), implementation_projects (2026_08_29_970004),
 * fleet_migration_instance_status (2026_08_29_970005),
 * offboarding_requests (2026_08_29_970006).
 *
 * export_jobs has NO pre-existing policy to flip FORCE on for — no
 * ENABLE ROW LEVEL SECURITY and no CREATE POLICY exist for it anywhere
 * yet. This migration does all three steps required by
 * docs/governance/future-table-requirements.md #4/#5 in one batch:
 * ENABLE ROW LEVEL SECURITY, CREATE POLICY, and FORCE ROW LEVEL
 * SECURITY — never leaving RLS-enabled-with-no-policy as an
 * intermediate state.
 *
 * Table selection rationale: export_jobs carries a direct, NOT NULL
 * firm_id column, cascadeOnDelete() (see database/migrations/
 * 2026_07_11_900011_create_export_jobs_table.php). The ExportJob model
 * uses BelongsToTenant + HasPublicUuid — a genuine tenant-owned row.
 *
 * Command shape: combined, symmetric, FOR ALL — export_jobs is fully
 * mutable via ExportJobService (Requested -> InProgress -> Completed/
 * Failed), matching every other table in this batch and the canonical
 * template used throughout this rollout.
 *
 * Known, deliberately-deferred gap (not closed by this migration):
 *   requested_by_firm_user_id is a nullable FK to firm_users with no
 *   composite foreign key or trigger tying firm_users.firm_id to this
 *   row's own firm_id — only ExportJobService::request()'s
 *   caller-supplied Firm/FirmUser objects are trusted, with no explicit
 *   cross-firm assertion performed by request() itself. Documented
 *   here as an accepted, residual application-layer gap — same posture
 *   as every other single-hop-FK gap accepted in this rollout (e.g.
 *   legal_holds' client_id/matter_id/document_id).
 *
 *   Standard caveat, same as every other cascade-on-firms table already
 *   forced in this repository: PostgreSQL's documented row-security
 *   semantics exempt foreign-key ON DELETE CASCADE actions from row-
 *   security policy evaluation entirely — deleting a firms row will
 *   always cascade-delete dependent export_jobs rows regardless of
 *   which tenant's context is currently active.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'export_jobs';

    private const POLICY = 'export_jobs_tenant_isolation';

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
