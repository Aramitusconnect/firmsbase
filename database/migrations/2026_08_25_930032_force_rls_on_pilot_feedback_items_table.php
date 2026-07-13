<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L Phase B6 — permanently activates FORCE ROW LEVEL
 * SECURITY for pilot_feedback_items, the sixth of 8 nullable-firm_id
 * checkpoints in this arc's remaining queue. Full design dossier:
 * rls-checkpoints/39a3l/B6-pilot_feedback_items-design-dossier.md
 * (APPROVED by both rls-policy-designer and tenant-context-auditor).
 *
 * pilot_feedback_items shares its origin migration with
 * backup_restore_tests/health_checks/incident_events/maintenance_windows
 * (database/migrations/2026_07_08_900008_extend_row_level_security_to_
 * phase_5_tenant_tables.php) — NOT notification_templates' Phase 4
 * lineage. Here, `firm_id` (along with `client_id`/`matter_id`/
 * `user_id`) is nullable because feedback has two distinct sources:
 * internal-source feedback (source = Internal) is never tied to any one
 * firm, while firm- or client-source feedback links back to whichever
 * firm/client/matter/user actually submitted it. NULL therefore means
 * "platform-scoped feedback, not one tenant's data" here, the same
 * general shape already proven for backup_restore_tests/health_checks/
 * incident_events/maintenance_windows (a platform-wide row, visible to
 * every tenant, never carrying another firm's content) — directly
 * transplantable without redesign.
 *
 * Like its four immediately-preceding Phase-5 siblings, this checkpoint
 * REPLACES the existing policy rather than leaving it untouched,
 * because firm_id is genuinely, legitimately NULL in normal operation
 * and the original single-expression policy (no IS NULL branch) would
 * hard-fail every INSERT of a null-firm_id row outright once FORCE is
 * active.
 *
 * Replacement: the exact same two-policy pattern already approved,
 * fixed, and live-verified for backup_restore_tests/health_checks/
 * incident_events/maintenance_windows/notification_templates
 * (2026_08_25_930027 through 2026_08_25_930031) — the SQL shape is
 * table-agnostic (firm_id nullable, no other structural dependency):
 *
 *   - pilot_feedback_items_tenant_read (FOR SELECT only): universal
 *     `firm_id IS NULL OR firm_id = current_firm` visibility — every
 *     tenant may see every platform-wide (internal-source) row,
 *     unchanged single-firm visibility for firm/client-source rows.
 *   - pilot_feedback_items_tenant_write (FOR ALL — not the invalid "FOR
 *     INSERT, UPDATE, DELETE" syntax found and fixed once, during
 *     backup_restore_tests' own checkpoint, and not repeated since):
 *     asymmetric on BOTH USING and WITH CHECK — a firm_id = NULL row
 *     may only be written/updated/deleted when NO tenant context is
 *     active, and a firm_id = X row may only be written/updated/
 *     deleted when the active context is exactly X. Closes both the
 *     INSERT-side forgery gap (a firm-scoped session inserting a fake
 *     "internal-source" row visible to every other firm) and the
 *     DELETE-side gap (a firm-scoped session deleting a real
 *     internal-source row every other firm still needs to see — WITH
 *     CHECK is never consulted for DELETE in PostgreSQL, so an
 *     asymmetric WITH CHECK alone would not close this).
 *
 * This table's own wrinkle, directly transplanted from
 * maintenance_windows (the same class of fix, not a new one): all six
 * PilotFeedbackService transition methods (triage(), startProgress(),
 * resolve(), markWontFix(), markDuplicate(), scheduleFollowUp()) follow
 * the shape `$item->update([...]); return $item->fresh();`.
 * Model::fresh() issues a NEW SELECT by primary key against the
 * database — it does not just return the in-memory object. Under FORCE
 * RLS, a firm-scoped row is only visible to that fresh SELECT when the
 * correct context is STILL active. PilotFeedbackService's application-
 * code prerequisite (already committed ahead of this migration) extends
 * the context wrap through the trailing ->fresh() re-read in all six
 * affected methods, not just the mutating ->update() call, each method
 * deriving its own context from the already-hydrated $item->firm_id
 * (no fresh unscoped SELECT needed to discover ownership) and branching
 * between runWithFirmContext()/runWithoutFirmContext() accordingly.
 * submit() itself takes an explicit ?Firm $firm parameter directly and
 * wraps only its own create() call — its return value is fully
 * materialized before the wrap's finally clears context, so it carries
 * no fresh()-after-clear risk.
 *
 * A second, genuinely new detail specific to this table (not shared by
 * any of the five prior nullable-firm_id checkpoints): unlike every
 * prior table's factory, which defaults firm_id to null,
 * PilotFeedbackItemFactory::definition() defaults 'firm_id' =>
 * Firm::factory() — non-null by default. tests/Feature/
 * Phase5PublicUuidTest.php:94's bare, unmodified
 * PilotFeedbackItem::factory()->create() call therefore exercises the
 * firm-scoped branch of the factory's context-hold create() override by
 * default, not the null branch every prior table's own bare factory
 * call exercised. The same symmetric grouping fix already shipped for
 * BackupRestoreTestFactory/HealthCheckFactory/IncidentEventFactory/
 * MaintenanceWindowFactory/NotificationTemplateFactory (group by
 * resolved firm_id, hold the matching tenant context, or clear it for
 * the null group, around each group's own store() call) handles this
 * correctly by construction — it was never actually dependent on which
 * branch happened to be a given table's default — but this checkpoint's
 * own verification explicitly re-runs Phase5PublicUuidTest.php under
 * real FORCE rather than assuming safety by analogy, since this is this
 * table's own analogue to notification_templates' cross-checkpoint
 * regression check.
 *
 * Zero live production call sites exist for this entire subsystem today
 * (PilotFeedbackService is exercised only by its own test file and
 * Phase5PublicUuidTest.php — confirmed via grep sweep of app/Http,
 * app/Jobs dispatch sites, routes/, bootstrap/app.php schedule wiring,
 * identical to all five prior tables' own confirmation method), so this
 * checkpoint makes currently-unreachable code correct under FORCE; it
 * does not newly expose a production surface.
 *
 * Known gaps NOT fixed in this batch (stated plainly, not hidden):
 *   - client_id/matter_id each reference a table with a required,
 *     single-owner firm_id (clients.firm_id, matters.firm_id are both
 *     non-null) — a pilot_feedback_items row's own firm_id could, in
 *     principle, mismatch the actual owning firm of its client_id/
 *     matter_id (e.g. firm_id = A while client_id points to a client
 *     owned by firm B), and neither the database nor
 *     PilotFeedbackService::submit() currently checks for this. RLS on
 *     pilot_feedback_items alone cannot catch this regardless of
 *     design — it governs row visibility by this table's own firm_id
 *     column only, and no single-table policy can enforce a cross-table
 *     composite constraint. Closing this would require a cross-table
 *     trigger or an application-level check, out of this checkpoint's
 *     narrow scope. user_id/created_by (also nullable FKs on this
 *     table) do not have the same well-defined mismatch risk, since a
 *     User can legitimately belong to multiple firms via firm_users —
 *     there is no single "owning firm" for a mismatch to be measured
 *     against. Both left untouched, matching the "RLS only ever governs
 *     firm_id" scope boundary already established for every prior table
 *     in this arc.
 *   - TenantContextService::runWithFirmContext() lacks the save/restore
 *     mechanism its sibling runWithoutFirmContext() already has, and
 *     could corrupt an ambient middleware-established context if ever
 *     called from inside an already-active, non-transaction-scoped
 *     outer context. Not unique to pilot_feedback_items (the identical
 *     pattern already exists, unfixed and equally latent, elsewhere in
 *     the codebase) and not exploitable today (zero live callers
 *     anywhere this pattern appears for this subsystem). Fixing
 *     runWithFirmContext() itself is out of this checkpoint's narrow
 *     approved scope (shared infrastructure touching 4+ unrelated
 *     files) and is tracked separately.
 *   - This subsystem's continued lack of a live production trigger is
 *     unchanged by this checkpoint and out of scope, matching all five
 *     prior tables' own scope boundary.
 *
 * down() restores the ORIGINAL single-expression policy byte-for-byte
 * (quoted directly from this table's own Phase 5 origin migration,
 * 2026_07_08_900008_extend_row_level_security_to_phase_5_tenant_tables.php)
 * and drops both new policies — a deviation from every FORCE-only-
 * with-no-policy-change migration's down(), required here because this
 * checkpoint's up() replaces the policy shape itself, not just the
 * FORCE flag.
 */
return new class extends Migration
{
    private const TABLE = 'pilot_feedback_items';

    private const ORIGINAL_POLICY = 'pilot_feedback_items_tenant_isolation';

    private const READ_POLICY = 'pilot_feedback_items_tenant_read';

    private const WRITE_POLICY = 'pilot_feedback_items_tenant_write';

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
