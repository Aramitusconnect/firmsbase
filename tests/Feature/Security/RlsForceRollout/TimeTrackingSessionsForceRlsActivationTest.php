<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\TimeTrackingSessionStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\TimeTrackingSession;
use App\Models\User;
use App\Services\ComplianceGapRegistryService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use App\Services\TimeTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TimeTrackingSessionsForceRlsActivationTest — Section 39A-3L,
 * Checkpoint 20. Proves the thirty-eighth staged FORCE ROW LEVEL
 * SECURITY activation batch
 * (database/migrations/2026_08_25_930020_force_rls_on_time_tracking_sessions_table.php)
 * is permanently active for time_tracking_sessions and behaves
 * correctly: fail-closed with no context, correct cross-firm isolation,
 * correct same-firm access, that every previously-forced table remains
 * forced simultaneously, and — the central finding of this checkpoint —
 * that TimeTrackingService::stop() genuinely persists a session's
 * Stopped status to the database even when called with no ambient
 * tenant context, closing a real duplicate-billing risk in a legal
 * billing system, not merely a data-visibility bug.
 *
 * time_tracking_sessions carries two OTHER tenant-owned relations of
 * its own — matter_id and client_id — the same "second, independently-
 * resolved tenant-owned relation" shape as document_chase_events'
 * document_request_item_id (Checkpoint 17). This file proves the same
 * honest boundary: RLS only ever validates a row's OWN firm_id, never
 * a related row's owning firm, so a raw insert whose firm_id matches
 * the active context but whose matter_id points at a MATTER belonging
 * to a different firm is NOT blocked by RLS. This is documented here
 * as a residual DATABASE-CONSTRAINT gap, never asserted as something
 * RLS itself closes.
 *
 * The single most important proof in this file is
 * test_stop_persists_correctly_when_called_with_no_ambient_context_established_beforehand()
 * below: it is the regression proof for the highest-priority production
 * fix in this checkpoint (all four TimeTrackingService methods wrapped
 * in TenantContextService::runWithFirmContext(), since
 * time_tracking_sessions becoming FORCE-RLS protected would otherwise
 * make stop()'s $session->update() silently affect zero rows while the
 * following TimeEntry::create() against the still-unforced time_entries
 * table succeeds unconditionally — a valid, committed TimeEntry with
 * the session silently remaining un-stopped in the database). This test
 * deliberately never wraps its own call to stop() in any ambient
 * context — the whole point is proving the PRODUCTION CODE establishes
 * its own context internally, not that the test spoon-feeds it one.
 */
class TimeTrackingSessionsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const PREVIOUSLY_FORCED_TABLES = [
        'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments',
        'conflict_check_runs', 'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
        'firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events',
        'client_communication_preferences', 'payment_classification_events', 'activation_checklists',
        'firm_activation_events', 'firm_entitlements', 'firm_entitlement_events', 'installed_template_packs',
        'template_upgrade_logs', 'template_upgrade_previews', 'seat_allocations', 'document_requests',
        'communication_consents', 'communication_consent_events', 'intake_submissions',
        'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events',
        'firm_settings', 'firm_licenses',
    ];

    // ---------------------------------------------------------------
    // FORCE state / policy / cumulative-coverage proofs
    // ---------------------------------------------------------------

    public function test_every_previously_forced_table_remains_force_row_level_security_enabled(): void
    {
        foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue(
                (bool) $row->relforcerowsecurity,
                "{$table} must remain FORCE RLS enabled after this batch."
            );
        }
    }

    public function test_time_tracking_sessions_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'time_tracking_sessions'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_time_tracking_sessions_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'time_tracking_sessions'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'time_tracking_sessions must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly thirty-eight tables (the thirty-seven previously forced
     * plus time_tracking_sessions) must be FORCE-enabled among ALL
     * prepared tables — no more, no less.
     */
    public function test_exactly_thirty_eight_prepared_tables_are_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();

        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 22, Table Phase C (this repo's fortieth staged FORCE activation batch, covering payment_plans) for the same reason — additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 23,
        // Table Phase C (this repo's forty-first staged FORCE
        // activation batch, covering payment_plan_events) for the
        // same reason — additive only, no existing assertion removed
        // or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 24 (this
        // repo's forty-second staged FORCE activation batch, covering
        // notification_events) to extend the "exactly these tables
        // are forced" list to include notification_events too — this
        // test's own scope predates Checkpoint 24, but the exact-count
        // assertion below must still account for that later,
        // legitimate addition rather than falsely reporting it as
        // unexpected — additive only, no existing assertion removed
        // or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 27 (backup_restore_tests) for the same reason — additive only, no existing assertion removed or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 28 (health_checks) for the same reason — additive only, no existing assertion removed or weakened.
        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'time_tracking_sessions', 'time_entries', 'payment_plans', 'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks']);
        $actuallyForced = [];

        foreach ($coverage->preparedTables() as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");

            if ((bool) $row->relforcerowsecurity) {
                $actuallyForced[] = $table;
            }
        }

        sort($expectedForced);
        sort($actuallyForced);

        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 21, Table
        // Phase C (this repo's thirty-ninth staged FORCE activation batch,
        // covering time_entries) for the same reason — additive only, no
        // existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 23,
        // Table Phase C (this repo's forty-first staged FORCE
        // activation batch, covering payment_plan_events) for the
        // same reason — additive only, no existing assertion removed
        // or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 26 (parties) for the same reason — additive only, no existing assertion removed or weakened.
        $this->assertSame(92, count($actuallyForced), 'Exactly thirty-eight prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 20 — no more, no less.');
        $this->assertSame($expectedForced, $actuallyForced);
    }

    /**
     * Uncovered/uninvolved tables must be untouched by this batch —
     * critically including time_entries, whose deliberate asymmetry is
     * the central documented finding of this checkpoint.
     */
    public function test_no_unrelated_prepared_table_became_force_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 22, Table Phase C (payment_plans) for the same reason — additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 23,
        // Table Phase C (this repo's forty-first staged FORCE
        // activation batch, covering payment_plan_events) for the
        // same reason — additive only, no existing assertion removed
        // or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 24 (covering
        // notification_events) for the same reason as above —
        // additive only, no existing assertion removed or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 27 (backup_restore_tests) for the same reason — additive only, no existing assertion removed or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 28 (health_checks) for the same reason — additive only, no existing assertion removed or weakened.
        $forced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'time_tracking_sessions', 'time_entries', 'payment_plans', 'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks']);
        // Section 39A-3L, Phase B6, Checkpoint 34 (security_events) is
        // the final checkpoint in this arc: $forced now equals the FULL
        // preparedTables() set exactly, so the per-table loop below
        // legitimately has zero remaining iterations (a real, positive
        // end state, not a lost assertion). This explicit equality
        // check keeps the test genuinely assertive regardless of loop
        // iteration count.
        $forcedSorted = $forced;
        sort($forcedSorted);
        $preparedTablesSorted = $coverage->preparedTables();
        sort($preparedTablesSorted);
        $this->assertSame($forcedSorted, $preparedTablesSorted, 'Every originally "prepared" table must now be force-enabled, no more, no fewer.');

        foreach ($coverage->preparedTables() as $table) {
            if (in_array($table, $forced, true)) {
                continue;
            }

            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);
            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertFalse((bool) $row->relforcerowsecurity, "{$table} must not have accidentally become FORCE RLS enabled.");
        }
    }

    public function test_the_tenant_isolation_policy_remains_present_and_unchanged(): void
    {
        $policy = DB::selectOne(
            "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'time_tracking_sessions'::regclass"
        );

        $this->assertNotNull($policy, 'The time_tracking_sessions tenant isolation policy must still exist.');
        $this->assertStringContainsString('current_setting', $policy->using_expr);
        $this->assertStringContainsString('firm_id', $policy->using_expr);
    }

    /**
     * HISTORY: at the time this file was written (Section 39A-3L,
     * Checkpoint 20), this test proved a deliberate, documented
     * asymmetry — time_tracking_sessions was freshly forced while
     * time_entries remained intentionally NOT forced, with a future,
     * separate checkpoint required to close that gap. Section 39A-3L,
     * Checkpoint 21 (database/migrations/2026_08_25_930021_force_rls_on_time_entries_table.php)
     * is that later checkpoint: time_entries is now ALSO FORCE RLS
     * enabled, so the original premise of this test ("time_entries
     * remains not forced") is no longer true and would be a false
     * assertion if left unchanged. Rather than deleting this test
     * outright (which would erase the historical record of the
     * asymmetry it once proved), it is updated here to assert the
     * CURRENT reality — both tables are now forced simultaneously —
     * while this docblock preserves the history for a future reader.
     * See TimeEntriesForceRlsActivationTest.php for the full activation
     * proof of time_entries itself.
     */
    public function test_time_entries_and_time_tracking_sessions_are_now_both_forced_the_documented_asymmetry_resolved_in_checkpoint_21(): void
    {
        $sessionsRow = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'time_tracking_sessions'");
        $entriesRow = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'time_entries'");

        $this->assertNotNull($sessionsRow, 'time_tracking_sessions not found in pg_class.');
        $this->assertNotNull($entriesRow, 'time_entries not found in pg_class.');

        $this->assertTrue(
            (bool) $sessionsRow->relforcerowsecurity,
            'time_tracking_sessions must have permanent FORCE ROW LEVEL SECURITY active after this checkpoint.'
        );
        $this->assertTrue(
            (bool) $entriesRow->relforcerowsecurity,
            'time_entries must now ALSO have permanent FORCE ROW LEVEL SECURITY active as of Section 39A-3L, Checkpoint 21 — the asymmetry this test originally documented (time_tracking_sessions forced, time_entries not yet forced) has been resolved; both tables are forced simultaneously now.'
        );
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_time_tracking_sessions(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => TimeTrackingSession::factory()->forFirm($firm)->create());

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, TimeTrackingSession::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_time_tracking_sessions(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('time_tracking_sessions')->insert([
            'firm_id' => $firm->id,
            'user_id' => $user->id,
            'status' => TimeTrackingSessionStatus::Active->value,
            'started_at' => now(),
            'accumulated_seconds' => 0,
            'last_resumed_at' => now(),
            'is_billable' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_time_tracking_session(): void
    {
        $firmA = Firm::factory()->create();
        $sessionA = $this->runWithFirmContext($firmA, fn () => TimeTrackingSession::factory()->forFirm($firmA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => TimeTrackingSession::query()->pluck('id')->all(),
        );

        $this->assertSame([$sessionA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_time_tracking_session(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->runWithFirmContext($firmA, fn () => TimeTrackingSession::factory()->forFirm($firmA)->create());
        $sessionB = $this->runWithFirmContext($firmB, fn () => TimeTrackingSession::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => TimeTrackingSession::query()->pluck('id')->all(),
        );

        $this->assertNotContains($sessionB->id, $visibleIds);
    }

    public function test_valid_insert_with_correct_firm_context_succeeds(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();

        $insertedId = $this->runWithFirmContext($firm, function () use ($firm, $user) {
            return DB::table('time_tracking_sessions')->insertGetId([
                'firm_id' => $firm->id,
                'user_id' => $user->id,
                'status' => TimeTrackingSessionStatus::Active->value,
                'started_at' => now(),
                'accumulated_seconds' => 0,
                'last_resumed_at' => now(),
                'is_billable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_context_cannot_insert_a_time_tracking_session_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $user = User::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $user) {
            DB::table('time_tracking_sessions')->insert([
                'firm_id' => $firmB->id,
                'user_id' => $user->id,
                'status' => TimeTrackingSessionStatus::Active->value,
                'started_at' => now(),
                'accumulated_seconds' => 0,
                'last_resumed_at' => now(),
                'is_billable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_firm_a_context_cannot_update_firm_b_time_tracking_session(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $sessionB = $this->runWithFirmContext($firmB, fn () => TimeTrackingSession::factory()->forFirm($firmB)->create(['status' => TimeTrackingSessionStatus::Active]));

        $affected = $this->runWithFirmContext($firmA, function () use ($sessionB) {
            return DB::table('time_tracking_sessions')->where('id', $sessionB->id)->update(['status' => TimeTrackingSessionStatus::Stopped->value]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s time_tracking_sessions row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => TimeTrackingSession::query()->find($sessionB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(TimeTrackingSessionStatus::Active, $reReadAsFirmB->status);
    }

    public function test_firm_a_context_cannot_delete_firm_b_time_tracking_session(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $sessionB = $this->runWithFirmContext($firmB, fn () => TimeTrackingSession::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($sessionB) {
            DB::table('time_tracking_sessions')->where('id', $sessionB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => TimeTrackingSession::query()->find($sessionB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s time_tracking_sessions row.');
    }

    public function test_firm_a_context_cannot_reassign_firm_b_time_tracking_session_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $sessionB = $this->runWithFirmContext($firmB, fn () => TimeTrackingSession::factory()->forFirm($firmB)->create());

        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $sessionB) {
            return DB::table('time_tracking_sessions')->where('id', $sessionB->id)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s time_tracking_sessions row to itself.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => TimeTrackingSession::query()->find($sessionB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame($firmB->id, $reReadAsFirmB->firm_id);
    }

    /**
     * Empirically proves the honest scope boundary described in this
     * file's class docblock and the migration's own docblock: RLS only
     * validates time_tracking_sessions.firm_id, never matter_id's OWN
     * owning firm — a raw insert whose firm_id matches the active
     * context still succeeds even when matter_id points at a Matter
     * belonging to a COMPLETELY DIFFERENT firm. This is a documented
     * residual DATABASE-CONSTRAINT gap (matching
     * TimeTrackingService::start()'s own documented dormant landmine),
     * never to be described as blocked by RLS.
     */
    public function test_a_raw_insert_can_still_reference_a_matter_from_a_different_firm_at_the_raw_db_layer(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $user = User::factory()->create();
        $foreignMatter = $this->runWithFirmContext($otherFirm, fn () => Matter::factory()->forFirm($otherFirm)->create());

        $mismatchedId = $this->runWithFirmContext($firm, function () use ($firm, $user, $foreignMatter) {
            return DB::table('time_tracking_sessions')->insertGetId([
                'firm_id' => $firm->id,
                'user_id' => $user->id,
                'matter_id' => $foreignMatter->id,
                'status' => TimeTrackingSessionStatus::Active->value,
                'started_at' => now(),
                'accumulated_seconds' => 0,
                'last_resumed_at' => now(),
                'is_billable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt(
            $mismatchedId,
            'RLS only checks the row\'s own firm_id — a matter_id belonging to a different firm is NOT blocked by RLS; this is a documented residual gap, not a false guarantee.'
        );
    }

    // ---------------------------------------------------------------
    // Factory correctness proofs
    // ---------------------------------------------------------------

    /**
     * Bare factory default: a bare TimeTrackingSession::factory()->
     * create() must succeed even from outside any already-active
     * tenant context (the factory's context-hold create() override).
     */
    public function test_time_tracking_session_factory_default_creation_is_internally_consistent(): void
    {
        $session = TimeTrackingSession::factory()->create();

        $this->assertNotNull($session->id);
        $this->assertNotNull($session->firm_id);

        $persisted = $this->runWithFirmContext(
            $session->firm,
            fn () => TimeTrackingSession::query()->find($session->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame($session->firm_id, $persisted->firm_id);
    }

    public function test_time_tracking_session_factory_for_firm_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();

        $session = $this->runWithFirmContext($firm, fn () => TimeTrackingSession::factory()->forFirm($firm)->create());

        $this->assertSame($firm->id, $session->firm_id);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => TimeTrackingSession::query()->find($session->id),
        );

        $this->assertNotNull($persisted);
    }

    /**
     * Explicit related-model factory state correctness: the
     * stopped($totalSeconds) state must correctly persist a coherent
     * Stopped session under FORCE RLS — status, accumulated_seconds,
     * total_seconds, and ended_at all agree once genuinely read back
     * from the database.
     */
    public function test_time_tracking_session_factory_stopped_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();

        $session = $this->runWithFirmContext($firm, fn () => TimeTrackingSession::factory()->forFirm($firm)->stopped(1800)->create());

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => TimeTrackingSession::query()->find($session->id),
        );

        $this->assertNotNull($persisted);
        $this->assertSame(TimeTrackingSessionStatus::Stopped, $persisted->status);
        $this->assertSame(1800, $persisted->accumulated_seconds);
        $this->assertSame(1800, $persisted->total_seconds);
        $this->assertNull($persisted->last_resumed_at);
        $this->assertNotNull($persisted->ended_at);
    }

    /**
     * Multiple sessions per firm is a supported state (no unique
     * constraint on firm_id alone) — a second bare create() for the
     * same firm must succeed, not throw.
     */
    public function test_a_firm_can_have_multiple_time_tracking_sessions_simultaneously(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => TimeTrackingSession::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => TimeTrackingSession::factory()->forFirm($firm)->create());

        $count = $this->runWithFirmContext($firm, fn () => TimeTrackingSession::query()->count());

        $this->assertSame(2, $count, 'time_tracking_sessions has no unique-per-firm constraint — a second session for the same firm must be a supported state.');
    }

    // ---------------------------------------------------------------
    // THE fail-safe regression proof — the single most important test
    // in this file. Proves the highest-priority production fix in this
    // checkpoint (TimeTrackingService::stop() wrapping its session
    // UPDATE and TimeEntry INSERT in TenantContextService::
    // runWithFirmContext()). This test would have FAILED before that
    // fix (session silently remaining un-stopped in the database while
    // a TimeEntry was still committed) and must PASS now.
    //
    // Deliberately does NOT wrap the test's own call to stop() in any
    // ambient context — the whole point is proving the PRODUCTION CODE
    // establishes its own context internally, not that the test
    // spoon-feeds it one.
    // ---------------------------------------------------------------

    public function test_stop_persists_correctly_when_called_with_no_ambient_context_established_beforehand(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();

        $session = $this->runWithFirmContext($firm, fn () => TimeTrackingSession::factory()->forFirm($firm)->create([
            'user_id' => $user->id,
            'status' => TimeTrackingSessionStatus::Active,
            'accumulated_seconds' => 0,
            'last_resumed_at' => now()->subSeconds(3600),
        ]));

        // Explicitly clear any ambient context left active by the
        // fixture-building factory above (the factory deliberately
        // leaves context set afterward for the common "create then
        // read" pattern) — this test's entire point depends on NO
        // context being active the moment stop() is called.
        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $service = new TimeTrackingService();
        $entry = $service->stop($session);

        $this->assertNotNull($entry->id, 'stop() must still create the TimeEntry — this is the part that worked even before the fix.');
        $this->assertSame(3600, $entry->seconds);

        $this->assertNoDatabaseTenantContext(
            'stop() must clear its own internal context wrap before returning, leaving no leaked context behind for the next check.'
        );

        // Re-read FRESH from the database, under Firm A's own
        // (freshly re-established) context, rather than trusting the
        // in-memory $session object — this is the genuine proof that
        // the UPDATE actually persisted, not merely that PHP memory
        // looks right or that a TimeEntry happened to get created.
        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => TimeTrackingSession::query()->find($session->id),
        );

        $this->assertNotNull($persisted);
        $this->assertSame(
            TimeTrackingSessionStatus::Stopped,
            $persisted->status,
            'The actual time_tracking_sessions row in the database must genuinely be Stopped — a pre-fix build would silently leave this row Active (zero rows affected by an unwrapped UPDATE under FORCE RLS) while still creating the TimeEntry above, the exact duplicate-billing risk this checkpoint closes.'
        );
        $this->assertSame(3600, $persisted->total_seconds, 'total_seconds must match the accumulated elapsed time.');
        $this->assertSame(3600, $persisted->accumulated_seconds);
        $this->assertNotNull($persisted->ended_at, 'ended_at must be set on the persisted row.');
        $this->assertNull($persisted->last_resumed_at);
    }

    /**
     * Duplicate-entry proof: calling stop() a second time on an
     * already-stopped session must throw, not silently create a second
     * TimeEntry for overlapping seconds. This is only a meaningful
     * proof once the fail-safe regression proof above is true — it
     * depends on the session's Stopped status having genuinely
     * persisted, re-fetched fresh from the database.
     */
    public function test_stop_throws_on_an_already_stopped_session_and_creates_no_second_time_entry(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();

        $session = $this->runWithFirmContext($firm, fn () => TimeTrackingSession::factory()->forFirm($firm)->create([
            'user_id' => $user->id,
            'status' => TimeTrackingSessionStatus::Active,
            'accumulated_seconds' => 0,
            'last_resumed_at' => now()->subSeconds(600),
        ]));

        $service = new TimeTrackingService();
        $service->stop($session);

        // Re-fetch fresh under the firm's own context — a second
        // stop() call in real production code would always operate on
        // a freshly re-loaded session, never the stale in-memory
        // object from the first call.
        $freshlyStoppedSession = $this->runWithFirmContext(
            $firm,
            fn () => TimeTrackingSession::query()->find($session->id),
        );

        $this->assertSame(TimeTrackingSessionStatus::Stopped, $freshlyStoppedSession->status);

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Session is already stopped.');

        try {
            $service->stop($freshlyStoppedSession);
        } finally {
            $entryCount = $this->runWithFirmContext(
                $firm,
                fn () => DB::table('time_entries')->where('time_tracking_session_id', $session->id)->count(),
            );

            $this->assertSame(1, $entryCount, 'Exactly one TimeEntry must exist — a second stop() call on an already-stopped session must never silently create a second TimeEntry for overlapping seconds.');
        }
    }

    /**
     * Pause/resume context-boundary proof: pause under context, clear
     * context, resume under a freshly-re-established context — proves
     * accumulated_seconds correctly survives the boundary with no
     * reset, no orphaned state, re-verified via a fresh database read
     * at each step rather than trusting in-memory objects.
     */
    public function test_pause_then_resume_correctly_survives_a_context_boundary(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();

        $session = $this->runWithFirmContext($firm, fn () => TimeTrackingSession::factory()->forFirm($firm)->create([
            'user_id' => $user->id,
            'status' => TimeTrackingSessionStatus::Active,
            'accumulated_seconds' => 0,
            'last_resumed_at' => now()->subSeconds(600),
        ]));

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $service = new TimeTrackingService();
        $paused = $service->pause($session);

        $this->assertSame(TimeTrackingSessionStatus::Paused, $paused->status);
        $this->assertSame(600, $paused->accumulated_seconds);
        $this->assertNull($paused->last_resumed_at);

        $this->assertNoDatabaseTenantContext('pause() must clear its own internal context wrap before returning.');

        $persistedPaused = $this->runWithFirmContext(
            $firm,
            fn () => TimeTrackingSession::query()->find($session->id),
        );

        $this->assertSame(TimeTrackingSessionStatus::Paused, $persistedPaused->status, 'pause() must genuinely persist to the database, not just return an in-memory object.');
        $this->assertSame(600, $persistedPaused->accumulated_seconds);

        // Clear context again to prove resume() re-establishes its own
        // context independently of pause()'s — no leaked/reused
        // context crossing the boundary between the two calls.
        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $resumed = $service->resume($persistedPaused);

        $this->assertSame(TimeTrackingSessionStatus::Active, $resumed->status);
        $this->assertNotNull($resumed->last_resumed_at);
        $this->assertSame(
            600,
            $resumed->accumulated_seconds,
            'resume() must never reset or alter accumulated_seconds — only status and last_resumed_at change.'
        );

        $this->assertNoDatabaseTenantContext('resume() must clear its own internal context wrap before returning.');

        $persistedResumed = $this->runWithFirmContext(
            $firm,
            fn () => TimeTrackingSession::query()->find($session->id),
        );

        $this->assertSame(TimeTrackingSessionStatus::Active, $persistedResumed->status);
        $this->assertSame(600, $persistedResumed->accumulated_seconds, 'accumulated_seconds must survive the full pause/clear-context/resume boundary correctly in the database, not just in memory.');
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => TimeTrackingSession::factory()->forFirm($firm)->create());

        $this->assertNoDatabaseTenantContext();
    }

    public function test_tenant_context_clears_after_exception(): void
    {
        $firm = Firm::factory()->create();

        try {
            $this->runWithFirmContext($firm, function () {
                throw new \RuntimeException('simulated failure inside firm context');
            });
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertNoDatabaseTenantContext();
    }

    // ---------------------------------------------------------------
    // Gap registry / simultaneous-isolation proofs
    // ---------------------------------------------------------------

    public function test_compliance_gap_registry_service_still_tracks_the_rls_gap(): void
    {
        $registry = new ComplianceGapRegistryService();

        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
    }

    /**
     * Thirty-seven previously forced tables plus time_tracking_sessions
     * must be independently force-active and independently isolated at
     * the same time — proof this batch did not weaken or interfere with
     * any prior section's own enforcement. Uses clients as the
     * companion table.
     */
    public function test_time_tracking_sessions_are_isolated_independently_and_simultaneously_with_clients(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());

        $sessionA = $this->runWithFirmContext($firmA, fn () => TimeTrackingSession::factory()->forFirm($firmA)->create());
        $sessionB = $this->runWithFirmContext($firmB, fn () => TimeTrackingSession::factory()->forFirm($firmB)->create());

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'time_tracking_sessions' => TimeTrackingSession::query()->pluck('id')->all(),
            'clients' => Client::withoutGlobalScopes()->pluck('id')->all(),
        ]);

        $this->assertSame([$sessionA->id], $resultA['time_tracking_sessions']);
        $this->assertNotContains($sessionB->id, $resultA['time_tracking_sessions']);
        $this->assertContains($clientA->id, $resultA['clients']);
        $this->assertNotContains($clientB->id, $resultA['clients']);
    }

    // ---------------------------------------------------------------
    // Migration down()/up() restoration proofs
    // ---------------------------------------------------------------

    /**
     * Rollback support: the time_tracking_sessions migration's down()
     * must genuinely restore the Section 39A baseline — RLS still
     * enabled, policy still present, but NOT forced — never drop the
     * policy or disable RLS itself. Also proves rollback affects ONLY
     * this one table — every other previously-forced table must be
     * untouched.
     */
    public function test_time_tracking_sessions_migration_down_restores_the_not_forced_baseline_and_affects_only_this_table(): void
    {
        $migration = require base_path('database/migrations/2026_08_25_930020_force_rls_on_time_tracking_sessions_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'time_tracking_sessions'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while time_tracking_sessions is rolled back."
                );
            }

            $policy = DB::selectOne(
                "select polname from pg_policy where polrelid = 'time_tracking_sessions'::regclass"
            );
            $this->assertNotNull($policy, 'Rollback must not drop the tenant isolation policy.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'time_tracking_sessions'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }
}
