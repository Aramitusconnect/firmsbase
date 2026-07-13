<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L Phase B6 — permanently activates FORCE ROW LEVEL
 * SECURITY for health_checks, the second of 8 nullable-firm_id
 * checkpoints in this arc's remaining queue. Full design dossier:
 * rls-checkpoints/39a3l/B6-health_checks-design-dossier.md (APPROVED
 * by both rls-policy-designer and tenant-context-auditor).
 *
 * Like backup_restore_tests immediately before it, this checkpoint
 * REPLACES the existing policy rather than leaving it untouched,
 * because `firm_id` is genuinely, legitimately NULL here in normal
 * operation: NULL = a platform-infrastructure check result (web
 * uptime, queue workers, scheduler, failed jobs, storage, email
 * delivery, payment webhooks, document scanning); non-null = a
 * TenantIsolationAnomalies result recorded for one specific firm.
 * Same origin migration as backup_restore_tests
 * (database/migrations/2026_07_08_900008_extend_row_level_security_
 * to_phase_5_tenant_tables.php), same original single-expression
 * policy shape, no separate WITH CHECK, no IS NULL branch — under
 * FORCE with that policy unchanged, every INSERT (platform-wide or
 * firm-specific) would fail WITH CHECK outright, for the identical
 * reason already proven and fixed for backup_restore_tests.
 *
 * Replacement: the exact same two-policy pattern already approved,
 * fixed, and live-verified for backup_restore_tests
 * (database/migrations/2026_08_25_930027_force_rls_on_backup_restore_
 * tests_table.php) — the SQL shape is table-agnostic (firm_id/
 * nullable, no other structural dependency):
 *
 *   - health_checks_tenant_read (FOR SELECT only): universal
 *     `firm_id IS NULL OR firm_id = current_firm` visibility — every
 *     tenant may see every platform-wide row, unchanged single-firm
 *     visibility for firm-specific rows.
 *   - health_checks_tenant_write (FOR ALL — not "FOR INSERT, UPDATE,
 *     DELETE", which is invalid PostgreSQL CREATE POLICY syntax, the
 *     mistake already found and fixed once during backup_restore_
 *     tests' own checkpoint and not repeated here): asymmetric on
 *     BOTH USING and WITH CHECK — a firm_id = NULL row may only be
 *     written/updated/deleted when NO tenant context is active, and a
 *     firm_id = X row may only be written/updated/deleted when the
 *     active context is exactly X. Closes both the INSERT-side
 *     forgery gap (a firm-scoped session inserting a fake
 *     "platform-wide" row visible to every other firm) and the
 *     DELETE-side gap (a firm-scoped session deleting a real
 *     platform-wide row every other firm still needs to see — WITH
 *     CHECK is never consulted for DELETE in PostgreSQL, so an
 *     asymmetric WITH CHECK alone would not close this).
 *
 * The one place this table differs materially from backup_restore_
 * tests, per the dossier: health_checks carries two free-form columns
 * (`detail`, `metadata_json`), and
 * TenantIsolationAnomalyService::checkForKnownAnomalyPatterns()
 * queries HealthCheck with NO firm_id filter in the PHP query at all,
 * republishing `detail` verbatim into whatever result
 * runAllAndRecord() then persists. This is resolved entirely by
 * correct RLS context establishment, not by new application-level
 * content filtering: HealthCheckService::runAllAndRecord()'s
 * prerequisite fix (already shipped, ahead of this migration) reads
 * all 9 check results under one context wrap matching the firm being
 * checked (or no context, for a platform-wide sweep), so Postgres's
 * own row-visibility enforcement — not the PHP query's lack of a
 * WHERE firm_id = ... clause — determines what
 * checkForKnownAnomalyPatterns() can actually see. A specific firm's
 * anomaly detail can therefore never reach a firm_id = NULL row
 * through this code path, because the read that produces the detail
 * string and the write that persists it both run under the identical
 * RLS-governed context. This is why runAllAndRecord() splits its read
 * phase from its write phase (one context for the read producing all
 * 9 results; each write then scoped to its own destined firm_id)
 * rather than using one whole-method wrap keyed only to the write
 * side — a whole-method wrap would also break the 8 always-platform-
 * wide writes under WITH CHECK whenever a firm is given, independent
 * of the anomaly-content question above.
 *
 * This checkpoint's application-code prerequisite
 * (HealthCheckService::runAllAndRecord()'s read/write phase split,
 * TenantIsolationAnomalyService::recordAnomaly()'s self-wrap, and
 * HealthCheckFactory's context-hold create() override with an
 * explicit null-firm_id branch) was already implemented ahead of this
 * migration, per the dossier's own note that — because application-
 * code changes are required here — the preparation and the FORCE
 * activation are split into two separate commits, matching the
 * contacts/parties (Checkpoints 25/26) and backup_restore_tests
 * (Checkpoint 27) precedent. Zero live production call sites exist
 * for this subsystem today (RunHealthChecksJob is fully built but
 * never dispatched or scheduled; TenantIsolationAnomalyService::
 * recordAnomaly() has zero callers; HealthCheckService::
 * isOverallHealthy() has zero callers — confirmed via grep sweep of
 * app/Http, app/Jobs dispatch sites, routes/, bootstrap/app.php
 * schedule wiring), so this checkpoint makes currently-unreachable
 * code correct under FORCE; it does not newly expose a production
 * surface.
 *
 * Known gap NOT fixed in this batch (stated plainly, not hidden):
 *   - recordAnomaly()'s caller-supplied $description/$metadata
 *     content is not validated or scrubbed for other-firm-identifying
 *     text. Not enforceable at the RLS layer (RLS controls row
 *     visibility by firm_id, not free-text content) — a caller-
 *     discipline concern for whoever eventually wires a real anomaly-
 *     detection trigger to this method. Zero current call sites, so
 *     nothing to fix today.
 *   - This subsystem's continued lack of a live production trigger
 *     (no schedule wiring for RunHealthChecksJob, no anomaly-
 *     detection call site) is unchanged by this checkpoint and is not
 *     this checkpoint's concern (table-by-table RLS scope, not a
 *     feature-completeness mandate).
 *
 * down() restores the ORIGINAL single-expression policy byte-for-byte
 * (quoted directly from 2026_07_08_900008_extend_row_level_security_
 * to_phase_5_tenant_tables.php) and drops both new policies — a
 * deviation from every FORCE-only-with-no-policy-change migration's
 * down(), required here because this checkpoint's up() replaces the
 * policy shape itself, not just the FORCE flag.
 */
return new class extends Migration
{
    private const TABLE = 'health_checks';

    private const ORIGINAL_POLICY = 'health_checks_tenant_isolation';

    private const READ_POLICY = 'health_checks_tenant_read';

    private const WRITE_POLICY = 'health_checks_tenant_write';

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
