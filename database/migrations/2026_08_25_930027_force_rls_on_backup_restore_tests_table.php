<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L Phase B6 — permanently activates FORCE ROW LEVEL
 * SECURITY for backup_restore_tests, the first of 8 nullable-firm_id
 * checkpoints in this arc's remaining queue. Full design dossier:
 * rls-checkpoints/39a3l/B6-backup_restore_tests-design-dossier.md
 * (APPROVED by both rls-policy-designer and tenant-context-auditor).
 *
 * Unlike every prior FORCE-only migration in this arc, this checkpoint
 * REPLACES the existing policy rather than leaving it untouched, because
 * `firm_id` is genuinely, legitimately NULL here in normal operation
 * (NULL = a platform-wide infrastructure drill; non-null = a drill that
 * specifically verified one firm's tenant_settings recovery) — the
 * first table in this mission where that is actually true, not merely
 * a schema artifact.
 *
 * The existing policy (database/migrations/2026_07_08_900008_extend_
 * row_level_security_to_phase_5_tenant_tables.php),
 * backup_restore_tests_tenant_isolation, USING
 * firm_id = NULLIF(current_setting('app.current_firm_id', true), '')::bigint,
 * no separate WITH CHECK, has NO `IS NULL` branch at all — a
 * platform-wide row would never satisfy `firm_id = <anything>` (SQL
 * NULL = NULL is NULL, not TRUE), so under FORCE with this policy
 * unchanged, every INSERT (platform-wide or firm-specific) would fail
 * WITH CHECK outright (current_setting() is never set when no context
 * is active, so firm_id = NULL for every comparison). This is a hard
 * failure, not a silent leak, but it is also not the desired behavior:
 * the design decision (dossier, "does every tenant get to see every
 * null-owned row?") is that a platform-wide row IS legitimately visible
 * to every firm — a platform infrastructure drill is not scoped to any
 * one firm's data, and nothing on the row (status, components_verified_
 * json, RPO/RTO numbers, notes, started_at/completed_at) is more
 * sensitive than what OperationalReadinessMappingService already tells
 * every tenant about platform readiness.
 *
 * Replacement: TWO policies, not one, per Design Reviewer 2's DELETE-
 * side gap finding. A single-policy design reusing one USING clause for
 * both SELECT and DELETE/UPDATE-old-row checks would let ANY firm-scoped
 * session `DELETE FROM backup_restore_tests WHERE firm_id IS NULL` and
 * succeed against every platform-wide row — WITH CHECK is never
 * consulted for DELETE in PostgreSQL, so an asymmetric WITH CHECK alone
 * (closing INSERT-side forgery) does nothing for this mirror-image
 * DELETE case. Not live-exploitable today (zero delete()/destroy() call
 * sites against this model, confirmed independently by both design
 * reviewers via grep sweep of app/Http, app/Jobs, routes/), but this
 * table is the copy-paste template for the remaining 7 nullable-firm_id
 * checkpoints, so the gap is closed now rather than propagated:
 *
 *   - backup_restore_tests_tenant_read (FOR SELECT only): universal
 *     `firm_id IS NULL OR firm_id = current_firm` visibility — every
 *     tenant may see every platform-wide row, unchanged single-firm
 *     visibility for firm-specific rows.
 *   - backup_restore_tests_tenant_write (FOR ALL — "FOR INSERT, UPDATE,
 *     DELETE" is not valid PostgreSQL CREATE POLICY syntax; only one
 *     command keyword is permitted, caught during test-writing and
 *     fixed to FOR ALL, which is behaviorally equivalent here since the
 *     write policy's condition is always a subset of the read policy's
 *     wider condition — see the dossier's "Post-approval correctness
 *     fix" note): asymmetric on BOTH USING and WITH CHECK — a
 *     firm_id = NULL row may only be written/updated/deleted when NO
 *     tenant context is active (a genuinely platform/admin-scoped
 *     connection), and a firm_id = X row may only be written/updated/
 *     deleted when the active context is exactly X. This closes both
 *     the INSERT-side forgery gap (a firm-scoped session could
 *     otherwise insert a fake "platform-wide" row visible to every
 *     other firm) and the DELETE-side gap (a firm-scoped session could
 *     otherwise delete a real platform-wide row that every other firm
 *     still needs to see).
 *
 * This mirrors the existing firm_users_self_lookup policy's own
 * precedent (2026_08_10_900001_add_self_lookup_clause_to_firm_users_
 * rls_policy.php) for exactly this USING/WITH-CHECK-sharing widening
 * class of bug: PostgreSQL combines multiple permissive policies for
 * the same command with OR, and a FOR SELECT-only policy is never
 * consulted for INSERT/UPDATE/DELETE, so the read-side widening cannot
 * leak into the write side.
 *
 * This checkpoint's application-code prerequisite (TenantContextService
 * ::runWithoutFirmContext(), BackupRestoreTestService::runDrill()/
 * latestFor() wrapping, and BackupRestoreTestFactory's context-hold
 * create() override with an explicit null-firm_id branch) was already
 * implemented ahead of this migration, per the dossier's own note that
 * — because application-code changes are required here, unlike every
 * FORCE-only-with-no-code-change checkpoint — the preparation and the
 * FORCE activation are split into two separate commits, matching the
 * contacts/parties (Checkpoints 25/26) precedent. Zero live production
 * call sites exist for this subsystem today (BackupRestoreTestService
 * is exercised only by its own test file — confirmed independently via
 * grep of app/Http, app/Jobs, routes/), so this checkpoint makes
 * currently-unreachable code correct under FORCE; it does not newly
 * expose a production surface.
 *
 * Known gap NOT fixed in this batch (stated plainly, not hidden):
 *   - No database-layer validation beyond this row's own firm_id/NULL
 *     shape — the same accepted "RLS only checks this row's own
 *     firm_id" boundary as every prior checkpoint. created_by (nullable
 *     FK to users) is unrelated to the firm-scoping question and is
 *     left untouched, per the dossier.
 *   - This subsystem's continued lack of any live production trigger
 *     is unchanged by this checkpoint and is not this checkpoint's
 *     concern (table-by-table RLS scope, not a feature-completeness
 *     mandate).
 *
 * down() restores the ORIGINAL single-expression policy byte-for-byte
 * (quoted directly from 2026_07_08_900008_extend_row_level_security_
 * to_phase_5_tenant_tables.php) and drops both new policies — a
 * deviation from every prior FORCE-only migration's down(), which only
 * ever toggled FORCE, required here because this checkpoint's up()
 * replaces the policy shape itself, not just the FORCE flag.
 */
return new class extends Migration
{
    private const TABLE = 'backup_restore_tests';

    private const ORIGINAL_POLICY = 'backup_restore_tests_tenant_isolation';

    private const READ_POLICY = 'backup_restore_tests_tenant_read';

    private const WRITE_POLICY = 'backup_restore_tests_tenant_write';

    public function up(): void
    {
        $table = $this->quoteIdentifier(self::TABLE);

        DB::statement("DROP POLICY {$this->quoteIdentifier(self::ORIGINAL_POLICY)} ON {$table}");

        DB::statement(<<<SQL
            CREATE POLICY {$this->quoteIdentifier(self::READ_POLICY)}
            ON {$table}
            FOR SELECT
            USING (
                firm_id IS NULL
                OR firm_id = NULLIF(current_setting('app.current_firm_id', true), '')::bigint
            )
        SQL);

        DB::statement(<<<SQL
            CREATE POLICY {$this->quoteIdentifier(self::WRITE_POLICY)}
            ON {$table}
            FOR ALL
            USING (
                (firm_id IS NULL AND NULLIF(current_setting('app.current_firm_id', true), '')::bigint IS NULL)
                OR firm_id = NULLIF(current_setting('app.current_firm_id', true), '')::bigint
            )
            WITH CHECK (
                (firm_id IS NULL AND NULLIF(current_setting('app.current_firm_id', true), '')::bigint IS NULL)
                OR firm_id = NULLIF(current_setting('app.current_firm_id', true), '')::bigint
            )
        SQL);

        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
    }

    public function down(): void
    {
        $table = $this->quoteIdentifier(self::TABLE);

        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");

        DB::statement("DROP POLICY {$this->quoteIdentifier(self::WRITE_POLICY)} ON {$table}");
        DB::statement("DROP POLICY {$this->quoteIdentifier(self::READ_POLICY)} ON {$table}");

        // Byte-for-byte restoration of the original Phase 5 preparation
        // policy text (2026_07_08_900008_extend_row_level_security_to_
        // phase_5_tenant_tables.php) — no IS NULL branch, no separate
        // WITH CHECK.
        DB::statement(<<<SQL
            CREATE POLICY {$this->quoteIdentifier(self::ORIGINAL_POLICY)} ON {$table}
            USING (firm_id = NULLIF(current_setting('app.current_firm_id', true), '')::bigint)
        SQL);
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $identifier)) {
            throw new \RuntimeException("Refusing to operate on an unsafe/unexpected identifier: {$identifier}");
        }

        return '"'.$identifier.'"';
    }
};
