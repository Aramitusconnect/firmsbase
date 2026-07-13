<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L Phase B6 — permanently activates FORCE ROW LEVEL
 * SECURITY for incident_events, the third of 8 nullable-firm_id
 * checkpoints in this arc's remaining queue. Full design dossier:
 * rls-checkpoints/39a3l/B6-incident_events-design-dossier.md (APPROVED
 * by both rls-policy-designer and tenant-context-auditor).
 *
 * Like backup_restore_tests and health_checks immediately before it,
 * this checkpoint REPLACES the existing policy rather than leaving it
 * untouched, because `firm_id` is genuinely, legitimately NULL here in
 * normal operation: NULL = a platform-wide incident (e.g. an
 * infrastructure-wide outage); non-null = an incident escalated for
 * one specific firm (e.g. a tenant isolation anomaly). Same origin
 * migration as backup_restore_tests/health_checks
 * (database/migrations/2026_07_08_900008_extend_row_level_security_
 * to_phase_5_tenant_tables.php), same original single-expression
 * policy shape (incident_events_tenant_isolation, USING firm_id =
 * NULLIF(current_setting('app.current_firm_id', true), '')::bigint,
 * no separate WITH CHECK, no IS NULL branch) — under FORCE with that
 * policy unchanged, every INSERT (platform-wide or firm-specific)
 * would fail WITH CHECK outright, for the identical reason already
 * proven and fixed for both prior tables.
 *
 * Replacement: the exact same two-policy pattern already approved,
 * fixed, and live-verified for backup_restore_tests/health_checks
 * (database/migrations/2026_08_25_930027_force_rls_on_backup_restore_
 * tests_table.php, 2026_08_25_930028_force_rls_on_health_checks_
 * table.php) — the SQL shape is table-agnostic (firm_id/nullable, no
 * other structural dependency):
 *
 *   - incident_events_tenant_read (FOR SELECT only): universal
 *     `firm_id IS NULL OR firm_id = current_firm` visibility — every
 *     tenant may see every platform-wide row, unchanged single-firm
 *     visibility for firm-specific rows.
 *   - incident_events_tenant_write (FOR ALL — not the invalid "FOR
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
 * The one place this table differs materially from both prior tables,
 * per the dossier: a genuine chicken-and-egg problem that neither
 * backup_restore_tests nor health_checks faced. IncidentService's six
 * append-style methods (updateSeverity, updateStatus, recordRootCause,
 * flagCustomerImpact, flagNotificationNeeded, resolve) must first call
 * currentState($correlationId) — an unscoped-by-firm read — to
 * discover an incident's *existing* ownership before knowing what
 * context to write under. Under FORCE RLS, currentState() needs the
 * correct context already active to find a firm-specific row, but the
 * caller does not know what that context should be until
 * currentState() returns it. Resolved not by a naive whole-method-wrap
 * transplant (which cannot work — there is no single point where "the
 * firm" is known before the read that is supposed to establish
 * context) but by adding a required `?Firm $firm` parameter to all six
 * previously-firm-blind methods, mirroring open()'s own existing
 * convention: the caller is expected to already know which firm (or
 * null) an incident belongs to when acting on it, exactly as it
 * already does for open(). Each of the six methods wraps its own
 * currentState() read and appendEvent() write in ONE context matching
 * its own $firm parameter; currentState()/timeline() themselves keep
 * their existing signatures — no $firm parameter, no self-wrap — so
 * there is only ever one wrap per operation, established by whichever
 * of the six public methods is the actual entry point (avoiding the
 * nested "decoy wrap" bug where a self-wrapping callee's own finally
 * clears an outer wrap's still-needed context).
 *
 * This design produces two DISTINCT asymmetric failure modes for a
 * mismatched $firm, not one blanket case (Design Reviewer 1's finding,
 * both proven separately in
 * tests/Feature/Security/RlsForceRollout/IncidentEventsForceRlsActivationTest.php):
 *   - A firm-scoped $firm against an incident actually owned by a
 *     DIFFERENT firm (or genuinely nonexistent under that context) →
 *     currentState()'s firstOrFail() throws ModelNotFoundException —
 *     the row is invisible under the wrong context, so the read itself
 *     fails.
 *   - A firm-scoped $firm against an incident that is actually
 *     PLATFORM-WIDE (firm_id = NULL) → currentState() SUCCEEDS (the
 *     read policy's `firm_id IS NULL` branch is unconditional, so the
 *     row is visible regardless of context), but appendEvent()'s
 *     subsequent INSERT ... firm_id = NULL is rejected by Postgres
 *     directly under a non-null WITH CHECK context (neither the
 *     write policy's null-branch nor match-branch is satisfied) — a
 *     row-level security policy violation raised at the write, not a
 *     ModelNotFoundException at the read. Both scenarios are
 *     fail-closed (no leak, no silent wrong-row write), but they throw
 *     at different points with different exception types.
 *
 * incident_events' cross-firm-content risk is structurally simpler
 * than health_checks' own: a given correlation_id's rows are never of
 * mixed ownership, because appendEvent() always copies firm_id forward
 * from the row it read ('firm_id' => $current->firm_id) — every row
 * sharing a correlation_id has identical firm_id, and there is no
 * cross-correlation_id aggregation anywhere in this service (unlike
 * health_checks' TenantIsolationAnomalies check, which read across the
 * whole table with no correlation_id-equivalent grouping key at all).
 *
 * This checkpoint's application-code prerequisite (IncidentService's
 * six methods gaining a required ?Firm $firm parameter, and
 * IncidentEventFactory's context-hold create() override with an
 * explicit null-firm_id branch, the same fix already shipped for
 * BackupRestoreTestFactory/HealthCheckFactory) was already implemented
 * ahead of this migration, per the dossier's own note that — because
 * application-code changes are required here — the preparation and the
 * FORCE activation are split into two separate commits, matching the
 * contacts/parties (Checkpoints 25/26) and backup_restore_tests/
 * health_checks (Checkpoints 27/28) precedent. Zero live production
 * call sites exist for this subsystem today (IncidentService is
 * exercised only by its own test file — confirmed via grep sweep of
 * app/Http, app/Jobs dispatch sites, routes/, bootstrap/app.php
 * schedule wiring, identical to both prior tables' own confirmation
 * method), so this checkpoint makes currently-unreachable code correct
 * under FORCE; it does not newly expose a production surface.
 *
 * Known gaps NOT fixed in this batch (stated plainly, not hidden):
 *   - currentState()/timeline() called directly (not through one of
 *     the six wrapped methods) for a firm-specific incident, with no
 *     or the wrong context established, will throw
 *     ModelNotFoundException (currentState(), via firstOrFail() —
 *     fail-loud) or silently return a partial/empty collection
 *     (timeline(), no firstOrFail() — fail-closed but silent), the
 *     same disclosed risk category already accepted for health_checks'
 *     checkForKnownAnomalyPatterns(). Zero current call sites exercise
 *     this directly.
 *   - A model returned by open() or any of the six wrapped methods has
 *     all its attributes already materialized before the wrap clears
 *     context, so direct attribute access is safe — but a LATER
 *     ->fresh(), ->refresh(), or lazy relation access on that same
 *     model runs under whatever context is active at that later point,
 *     not the context that produced the row. Zero current call sites
 *     do this.
 *   - TenantContextService::runWithFirmContext() lacks the save/
 *     restore mechanism its sibling runWithoutFirmContext() already
 *     has, and could corrupt an ambient middleware-established context
 *     if ever called from inside an already-active, non-transaction-
 *     scoped outer context. Not unique to incident_events (the
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
 *     unchanged by this checkpoint and out of scope, matching both
 *     prior tables' own scope boundary. Firm::incidentEvents() has
 *     zero callers, confirmed, not touched by this checkpoint.
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
    private const TABLE = 'incident_events';

    private const ORIGINAL_POLICY = 'incident_events_tenant_isolation';

    private const READ_POLICY = 'incident_events_tenant_read';

    private const WRITE_POLICY = 'incident_events_tenant_write';

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
