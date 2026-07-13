<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L Phase B6 — permanently activates FORCE ROW LEVEL
 * SECURITY for maintenance_windows, the fourth of 8 nullable-firm_id
 * checkpoints in this arc's remaining queue. Full design dossier:
 * rls-checkpoints/39a3l/B6-maintenance_windows-design-dossier.md
 * (APPROVED by both rls-policy-designer and tenant-context-auditor).
 *
 * Like backup_restore_tests, health_checks, and incident_events
 * immediately before it, this checkpoint REPLACES the existing policy
 * rather than leaving it untouched, because `firm_id` is genuinely,
 * legitimately NULL here in normal operation: NULL = a platform-wide
 * maintenance window (e.g. a shared-infrastructure upgrade every
 * tenant is affected by); non-null = a maintenance window scoped to
 * one specific dedicated/private-deployment firm only. Same origin
 * migration as backup_restore_tests/health_checks/incident_events
 * (database/migrations/2026_07_08_900008_extend_row_level_security_
 * to_phase_5_tenant_tables.php), same original single-expression
 * policy shape (maintenance_windows_tenant_isolation, USING firm_id =
 * NULLIF(current_setting('app.current_firm_id', true), '')::bigint,
 * no separate WITH CHECK, no IS NULL branch) — under FORCE with that
 * policy unchanged, every INSERT (platform-wide or firm-specific)
 * would fail WITH CHECK outright, for the identical reason already
 * proven and fixed for all three prior tables.
 *
 * Replacement: the exact same two-policy pattern already approved,
 * fixed, and live-verified for backup_restore_tests/health_checks/
 * incident_events (database/migrations/2026_08_25_930027_force_rls_on_
 * backup_restore_tests_table.php, 2026_08_25_930028_force_rls_on_
 * health_checks_table.php, 2026_08_25_930029_force_rls_on_incident_
 * events_table.php) — the SQL shape is table-agnostic (firm_id/
 * nullable, no other structural dependency):
 *
 *   - maintenance_windows_tenant_read (FOR SELECT only): universal
 *     `firm_id IS NULL OR firm_id = current_firm` visibility — every
 *     tenant may see every platform-wide row, unchanged single-firm
 *     visibility for firm-specific rows.
 *   - maintenance_windows_tenant_write (FOR ALL — not the invalid "FOR
 *     INSERT, UPDATE, DELETE" syntax found and fixed once, during
 *     backup_restore_tests' own checkpoint, and not repeated since):
 *     asymmetric on BOTH USING and WITH CHECK — a firm_id = NULL row
 *     may only be written/updated/deleted when NO tenant context is
 *     active, and a firm_id = X row may only be written/updated/
 *     deleted when the active context is exactly X. Closes both the
 *     INSERT-side forgery gap (a firm-scoped session inserting a fake
 *     "platform-wide" row visible to every other firm) and the
 *     DELETE-side gap (a firm-scoped session deleting a real
 *     platform-wide row every other firm still needs to see — WITH
 *     CHECK is never consulted for DELETE in PostgreSQL, so an
 *     asymmetric WITH CHECK alone would not close this).
 *
 * This table is closer to a safe transplant than either
 * backup_restore_tests or incident_events — neither health_checks'
 * mixed-ownership-batch-write problem nor incident_events' chicken-
 * and-egg ownership-discovery problem applies here: reschedule()
 * writes two rows in one call (an UPDATE on the existing window, an
 * INSERT for the new one), but both always share IDENTICAL ownership
 * — the new row's firm_id is copied verbatim from $window->firm_id,
 * never independently derived — and none of the six
 * MaintenanceWindowService methods needs to discover ownership via a
 * fresh, unscoped SELECT: five of them receive an already-hydrated
 * MaintenanceWindow $window parameter, so $window->firm_id is
 * available synchronously with no read required, and schedule()
 * receives an explicit ?Firm $firm parameter directly, identical in
 * shape to every prior table's own open()/runDrill() convention.
 *
 * This table's OWN distinguishing wrinkle, genuinely new in this arc
 * (neither health_checks' mixed-ownership problem nor incident_events'
 * chicken-and-egg read problem): start(), complete(), cancel(), and
 * markCustomerNotificationSent() all follow the shape
 * `$window->update([...]); return $window->fresh();`. Model::fresh()
 * issues a NEW SELECT by primary key against the database — it does
 * not just return the in-memory object. Under FORCE RLS, a
 * firm-scoped row is only visible to that fresh SELECT when the
 * correct context is STILL active. Scoping the context wrap only
 * around the ->update() call (a plausible under-scoping mistake, since
 * ->update() is the only statement that visibly "does something")
 * would let ->fresh() run AFTER the wrap's finally has already cleared
 * context — for a firm-scoped window, ->fresh() would then silently
 * return null (not an exception; fresh() returns null when the row
 * isn't found), breaking every one of these methods' return contract
 * for every firm-scoped window while remaining completely invisible
 * against the pre-existing test suite, since every pre-existing test
 * only ever exercised firm_id = null windows (visible under any
 * context, including none). MaintenanceWindowService's application-
 * code prerequisite (already committed ahead of this migration)
 * extends the context wrap through the trailing ->fresh() re-read in
 * all four affected methods, not just the mutating ->update() call —
 * directly proven in this checkpoint's own new test file via a full
 * schedule -> start -> complete lifecycle, plus cancel() and
 * markCustomerNotificationSent(), each exercised against a
 * firm-scoped window.
 *
 * reschedule()'s pre-existing internal DB::transaction() is left in
 * place — once nested inside runWithFirmContext()'s own transaction,
 * it becomes a savepoint boundary rather than a no-op, harmless and
 * preserving the original explicit rollback-on-error guarantee at
 * zero additional cost.
 *
 * This checkpoint's application-code prerequisite
 * (MaintenanceWindowService's four affected methods gaining a context
 * wrap that extends through their trailing ->fresh() call, and
 * MaintenanceWindowFactory's context-hold create() override with an
 * explicit null-firm_id branch, the same fix already shipped for
 * BackupRestoreTestFactory/HealthCheckFactory/IncidentEventFactory)
 * was already implemented ahead of this migration, per the dossier's
 * own note that — because application-code changes are required here
 * — the preparation and the FORCE activation are split into two
 * separate commits, matching the contacts/parties (Checkpoints
 * 25/26) and backup_restore_tests/health_checks/incident_events
 * (Checkpoints 27/28/29) precedent. Zero live production call sites
 * exist for this subsystem today (MaintenanceWindowService is
 * exercised only by its own test file — confirmed via grep sweep of
 * app/Http, app/Jobs dispatch sites, routes/, bootstrap/app.php
 * schedule wiring, identical to all three prior tables' own
 * confirmation method), so this checkpoint makes currently-
 * unreachable code correct under FORCE; it does not newly expose a
 * production surface.
 *
 * Known gaps NOT fixed in this batch (stated plainly, not hidden):
 *   - Zero existing pre-prerequisite test coverage of a non-null-
 *     firm_id window's full lifecycle existed before this checkpoint's
 *     own new activation test — the first place this path is
 *     exercised at all, for either the preparation or the FORCE-
 *     activation half.
 *   - TenantContextService::runWithFirmContext() lacks the save/
 *     restore mechanism its sibling runWithoutFirmContext() already
 *     has, and could corrupt an ambient middleware-established context
 *     if ever called from inside an already-active, non-transaction-
 *     scoped outer context. Not unique to maintenance_windows (the
 *     identical pattern already exists, unfixed and equally latent, in
 *     app/Models/User.php, TrustLedgerEntryReversalService,
 *     EmailBodyEncryptionService, DowngradeEvaluationService) and not
 *     exploitable today (zero live callers anywhere this pattern
 *     appears, invisible to the existing test suite since every test
 *     already runs inside RefreshDatabase's own transaction). Fixing
 *     runWithFirmContext() itself is out of this checkpoint's narrow
 *     approved scope (shared infrastructure touching 4+ unrelated
 *     files) and is tracked separately.
 *   - This subsystem's continued lack of a live production trigger is
 *     unchanged by this checkpoint and out of scope, matching all
 *     three prior tables' own scope boundary. Firm::maintenanceWindows()
 *     relation has zero callers, confirmed, not touched by this
 *     checkpoint.
 *   - Column-level sensitivity of private_message (readable by anyone
 *     who can see the row, since RLS is row-level not column-level) is
 *     an application-layer concern for whoever eventually builds a
 *     real reader (e.g. a status page), same accepted precedent as
 *     health_checks' free-text fields, not a gap introduced by this
 *     checkpoint.
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
    private const TABLE = 'maintenance_windows';

    private const ORIGINAL_POLICY = 'maintenance_windows_tenant_isolation';

    private const READ_POLICY = 'maintenance_windows_tenant_read';

    private const WRITE_POLICY = 'maintenance_windows_tenant_write';

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
