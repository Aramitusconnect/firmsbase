<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L Phase B6 — permanently activates FORCE ROW LEVEL
 * SECURITY for notification_templates, the fifth of 8 nullable-firm_id
 * checkpoints in this arc's remaining queue. Full design dossier:
 * rls-checkpoints/39a3l/B6-notification_templates-design-dossier.md
 * (APPROVED by both rls-policy-designer and tenant-context-auditor).
 *
 * Unlike the four prior nullable-firm_id checkpoints (backup_restore_
 * tests, health_checks, incident_events, maintenance_windows), this
 * table's originating migration is database/migrations/2026_07_07_
 * 800016_extend_row_level_security_to_phase_4_tenant_tables.php — NOT
 * the Phase 5 migration the four prior tables shared. This is purely
 * cosmetic to the FORCE approach itself (the original single-expression
 * policy shape is identical either way), but matters for down(), which
 * must restore this table's own original policy text, quoted from its
 * own origin file, not copy-pasted from the Phase 5 migration the
 * prior four checkpoints used. Verified directly against
 * 2026_07_07_800016_extend_row_level_security_to_phase_4_tenant_tables.php
 * before writing this migration.
 *
 * Like its four predecessors, this checkpoint REPLACES the existing
 * policy rather than leaving it untouched, because firm_id is
 * genuinely, legitimately NULL here in normal operation. Here, though,
 * the semantics of that NULL are different from all four prior tables
 * (which used NULL for "platform monitoring/infrastructure event, not
 * tied to any one firm"): NULL on notification_templates means "global
 * default template", and non-null means "one firm's override of that
 * default" (database/migrations/2026_07_07_800009_create_notification_
 * templates_table.php:8-11). NotificationTemplateService::resolve()
 * implements the fallback lookup — a firm's own override first, then
 * the global default. This is a SAFER variant of the nullable-firm_id
 * pattern, not a harder one: a global (null-firm_id) row can never
 * carry another firm's content, by construction, since createGlobalDefault()
 * and createFirmOverride() are entirely separate code paths and nothing
 * ever copies firm-specific data into a null-firm_id row. The standard
 * read policy (firm_id IS NULL OR firm_id = current_firm) is exactly
 * what resolve()'s fallback needs — no redesign required on the read
 * side.
 *
 * Replacement: the exact same two-policy pattern already approved,
 * fixed, and live-verified for backup_restore_tests/health_checks/
 * incident_events/maintenance_windows (2026_08_25_930027 through
 * 2026_08_25_930030) — the SQL shape is table-agnostic (firm_id
 * nullable, no other structural dependency):
 *
 *   - notification_templates_tenant_read (FOR SELECT only): universal
 *     `firm_id IS NULL OR firm_id = current_firm` visibility — every
 *     tenant may see every global-default row, unchanged single-firm
 *     visibility for firm-specific override rows.
 *   - notification_templates_tenant_write (FOR ALL — not "FOR INSERT,
 *     UPDATE, DELETE"): asymmetric on BOTH USING and WITH CHECK — a
 *     firm_id = NULL row may only be written/updated/deleted when NO
 *     tenant context is active, and a firm_id = X row may only be
 *     written/updated/deleted when the active context is exactly X.
 *     Closes both the INSERT-side forgery gap (a firm-scoped session
 *     inserting a fake "global default" row visible to every other
 *     firm) and the DELETE-side gap (a firm-scoped session deleting a
 *     real global-default row every other firm still needs) — WITH
 *     CHECK is never consulted for DELETE in PostgreSQL, so an
 *     asymmetric WITH CHECK alone would not close this.
 *
 * This checkpoint's own distinguishing wrinkle: six previously
 * completely-unwrapped write pathways across two service files —
 * NotificationTemplateService::createGlobalDefault()/createFirmOverride()/
 * archive(), and SenderDomainVerificationService::markVerified()/
 * markFailed()/syncVerificationAcrossFirmTemplates(). archive(),
 * markVerified(), and markFailed() all follow the same
 * `$template->update([...]); return $template->fresh();` shape already
 * fixed for maintenance_windows' start()/complete()/cancel()/
 * markCustomerNotificationSent() — the context wrap must extend
 * through the trailing ->fresh() re-read, not stop at ->update(), or a
 * firm-scoped template's ->fresh() would silently return null once
 * FORCE is active. syncVerificationAcrossFirmTemplates() is a raw
 * DB::table('notification_templates')->where('firm_id', $firmId)->...
 * ->update(...) batch call — under FORCE and no context, a firm-scoped
 * $firmId would silently update zero rows (no error), while a null
 * $firmId would, by coincidence of NULLIF() evaluating unset settings
 * to NULL, actually succeed — undefined-by-accident behavior in both
 * sub-cases, not correct-by-design. All six methods are fixed ahead of
 * this migration (application-code prerequisite, already committed
 * separately, matching the contacts/parties and backup_restore_tests/
 * health_checks/incident_events/maintenance_windows precedent of
 * splitting preparation and FORCE activation into two commits):
 * createGlobalDefault() always runs inside runWithoutFirmContext() (no
 * $firm parameter exists on this method); createFirmOverride() always
 * runs inside runWithFirmContext($firm, ...) (a required, non-nullable
 * $firm parameter); archive()/markVerified()/markFailed() derive their
 * context from the already-hydrated $template->firm_id already in PHP
 * memory (no re-query needed) and branch between runWithFirmContext()
 * and runWithoutFirmContext() accordingly, with the wrap extending
 * through ->fresh(); syncVerificationAcrossFirmTemplates() branches
 * the same way on its explicit ?int $firmId parameter, wrapping the
 * entire raw-query body. resolve() itself stays completely unwrapped
 * and signature-unchanged — it is a pure read whose sole caller
 * (NotificationDispatchService::dispatch()) already establishes the
 * correct whole-method context, mirroring health_checks'
 * checkForKnownAnomalyPatterns() and incident_events' currentState()/
 * timeline() already-approved design. None of these six methods call
 * each other internally, so there is no self-inflicted nested-wrap
 * risk within this checkpoint's own code.
 *
 * NotificationTemplateFactory::create() received the same context-hold
 * override already shipped for BackupRestoreTestFactory/HealthCheckFactory/
 * IncidentEventFactory/MaintenanceWindowFactory, grouping bare-created
 * models by their resolved firm_id and holding the matching tenant
 * context (or clearing it, for the null-firm_id group) around each
 * group's underlying store() call. This is not merely pattern-matching
 * the prior four factories — it closes a real, already-latent
 * regression in an already-committed, already-passing test for a
 * DIFFERENT, already-forced table: tests/Feature/Security/RlsForceRollout/
 * NotificationEventsForceRlsActivationTest.php:483 calls
 * NotificationTemplate::factory()->forFirm($otherFirm)->create() with
 * no ambient context at all, immediately before its own
 * runWithFirmContext($firm, ...) wrap starts two lines later. This
 * currently passes only because notification_templates isn't forced
 * yet (the table owner bypasses RLS). Once forced, this line would
 * fail its INSERT outright without the factory fix, which correctly
 * establishes $otherFirm's context internally (grouped by the row's
 * own resolved firm_id) before the row is stored, and does not
 * interfere with the subsequent runWithFirmContext($firm, ...) call
 * for a different firm, since the factory's own wrap has already
 * returned and cleared by the time that call begins — the same
 * established interaction already proven safe elsewhere in this arc
 * (e.g. ClientFactory's own precedent). This checkpoint's own
 * verification explicitly re-runs the full, already-committed
 * NotificationEventsForceRlsActivationTest.php, plus
 * CommunicationConsentsForceRlsActivationTest.php and
 * NotificationDispatchServiceTest.php (both create firm_id => null
 * rows with cleared ambient context — independently traced as safe,
 * since the null-branch succeeds deterministically once the factory
 * fix is applied, but verified rather than assumed), not merely this
 * checkpoint's own new test file, since that is the one place a
 * genuine cross-checkpoint regression could surface.
 *
 * Zero live production call sites exist for this entire subsystem
 * today (NotificationDispatchService::dispatch(), the sole caller of
 * resolve(), itself has zero callers — confirmed via grep sweep of
 * app/Http, app/Jobs dispatch sites, routes/, bootstrap/app.php
 * schedule wiring, identical to all four prior tables' own
 * confirmation method), so this checkpoint makes currently-unreachable
 * code correct under FORCE; it does not newly expose a production
 * surface.
 *
 * Known gaps NOT fixed in this batch (stated plainly, not hidden):
 *   - TenantContextService::runWithFirmContext() lacks the save/
 *     restore mechanism its sibling runWithoutFirmContext() already
 *     has, and could corrupt an ambient middleware-established context
 *     if ever called from inside an already-active, non-transaction-
 *     scoped outer context. Not unique to notification_templates
 *     (identical latent pattern already exists elsewhere in the
 *     codebase, unfixed) and not exploitable today (zero live callers
 *     anywhere this pattern appears for this subsystem). Fixing
 *     runWithFirmContext() itself is out of this checkpoint's narrow
 *     approved scope (shared infrastructure touching 4+ unrelated
 *     files) and is tracked separately.
 *   - This subsystem's continued lack of a live production trigger is
 *     unchanged by this checkpoint and out of scope, matching all four
 *     prior tables' own scope boundary.
 *
 * down() restores the ORIGINAL single-expression policy byte-for-byte
 * (quoted directly from this table's own origin migration,
 * 2026_07_07_800016_extend_row_level_security_to_phase_4_tenant_tables.php
 * — NOT the Phase 5 migration used by backup_restore_tests/health_checks/
 * incident_events/maintenance_windows) and drops both new policies — a
 * deviation from every FORCE-only-with-no-policy-change migration's
 * down(), required here because this checkpoint's up() replaces the
 * policy shape itself, not just the FORCE flag.
 */
return new class extends Migration
{
    private const TABLE = 'notification_templates';

    private const ORIGINAL_POLICY = 'notification_templates_tenant_isolation';

    private const READ_POLICY = 'notification_templates_tenant_read';

    private const WRITE_POLICY = 'notification_templates_tenant_write';

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

        // Byte-for-byte restoration of the original Phase 4 preparation
        // policy text (2026_07_07_800016_extend_row_level_security_to_
        // phase_4_tenant_tables.php — this table's own origin migration,
        // NOT the Phase 5 migration the four prior nullable-firm_id
        // checkpoints shared) — no IS NULL branch, no separate WITH
        // CHECK.
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
