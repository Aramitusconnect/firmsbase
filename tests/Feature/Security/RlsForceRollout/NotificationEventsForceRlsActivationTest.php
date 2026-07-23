<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Enums\DocumentRequestItemStatus;
use App\Enums\NotificationEventStatus;
use App\Enums\NotificationTemplateStatus;
use App\Models\Client;
use App\Models\CommunicationConsent;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\NotificationEvent;
use App\Models\NotificationTemplate;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanEvent;
use App\Services\ComplianceGapRegistryService;
use App\Services\ConsentService;
use App\Services\DocumentChaseService;
use App\Services\NotificationDispatchService;
use App\Services\NotificationEligibilityService;
use App\Services\NotificationTemplateService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\SenderDomainVerificationService;
use App\Services\SuppressionService;
use App\Services\TenantContextService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * NotificationEventsForceRlsActivationTest — Section 39A-3L, Checkpoint
 * 24. Proves the forty-second staged FORCE ROW LEVEL SECURITY
 * activation batch (database/migrations/2026_08_25_930024_force_rls_
 * on_notification_events_table.php) is permanently active for
 * notification_events and behaves correctly: fail-closed with no
 * context, correct cross-firm isolation, correct same-firm access,
 * that every previously-forced table (including payment_plan_events,
 * forced one checkpoint earlier) remains forced simultaneously, and —
 * the central finding of this checkpoint — that all five verified
 * write paths (NotificationDispatchService::dispatch(), whose entire
 * body — including all four internal recordEvent() call sites and the
 * eligibility gate — now runs inside a single runWithFirmContext() wrap
 * keyed off its own $firm parameter, recordSent(), recordFailed(), and
 * SuppressionService::recordBounce()/recordComplaint()) genuinely
 * persist notification_events rows with no ambient tenant context
 * required from the caller.
 *
 * Unlike every prior checkpoint in this arc, ALL FIVE of these writer
 * methods have ZERO production callers anywhere in the codebase today
 * (confirmed by the implementer's repository-wide search, documented
 * in the migration's own docblock) — the entire notification_events
 * write pathway is dormant, wired now purely so it is not a landmine
 * for whoever wires a live caller in next. This is expected and
 * correct, not a gap in this test file's design: every writer proof
 * below calls the method DIRECTLY, since there is no real business-
 * flow entry point to exercise instead.
 *
 * notification_template_id, client_id, and matter_id are all nullable
 * on this table (unlike payment_plan_events' NOT NULL payment_plan_id)
 * — confirmed directly in NotificationEventFactory's own definition(),
 * which already defaults all three to null. This means no "one
 * authoritative firm" factory fix was required this checkpoint (unlike
 * PaymentPlanEventFactory at Checkpoint 23): a bare
 * NotificationEvent::factory()->create() cannot produce a cross-firm
 * relation mismatch, because it references no other tenant-owned row
 * at all by default. The standard context-hold create() override was
 * still added, matching this mission's universal safety-net
 * convention for every FORCE-RLS factory regardless of whether a
 * bare-call bug exists today.
 *
 * This file also proves the same honest RLS scope boundary as every
 * prior checkpoint: RLS only ever validates a row's OWN firm_id, never
 * a related row's owning firm — a raw insert whose firm_id matches the
 * active context but whose client_id/matter_id/notification_template_id
 * points at a row belonging to a different firm is NOT blocked by RLS.
 * This is documented here as a residual DATABASE-CONSTRAINT gap, never
 * asserted as something RLS itself closes — proven independently for
 * all three nullable foreign keys, since each is an independently
 * resolved relation.
 *
 * CORRECTED finding, superseding an earlier version of this file: a
 * genuine bug was found and reported here when notification_events
 * first activated FORCE RLS at this checkpoint — dispatch()'s four
 * recordEvent() call sites were each independently wrapped in their
 * own runWithFirmContext() call, so the first wrap's own finally
 * unconditionally cleared app.current_firm_id (PostgreSQL SET LOCAL is
 * scoped to the enclosing REAL transaction, not a nested savepoint)
 * before NotificationEligibilityService::check() — which transitively
 * calls ConsentService::isGranted() (unwrapped, reads FORCE-RLS-
 * protected communication_consents) — ever ran. The net effect was
 * that dispatch() could never reach its own Queued/accepted=true
 * branch under any calling scenario, always misreporting "no granted
 * consent" even when a real grant genuinely existed.
 *
 * That bug has since been fixed in
 * app/Services/NotificationDispatchService.php (see its own class
 * docblock): dispatch() now wraps its ENTIRE body — including the
 * eligibility gate — in a single runWithFirmContext($firm, ...) call,
 * established from its own $firm parameter and held for its entire
 * execution. It no longer depends on any ambient/leaked context from a
 * caller or a factory's context-hold override, and it no longer fails
 * closed when called with zero pre-existing context, because it
 * doesn't need pre-existing context at all anymore. This is now
 * positively proven below (see
 * test_dispatch_succeeds_and_persists_a_queued_event_and_queues_a_job_with_zero_ambient_context_established_beforehand),
 * matching tests/Feature/Notifications/NotificationDispatchServiceTest.php::
 * test_dispatch_succeeds_and_queues_a_job_when_every_gate_passes, which
 * is verified passing again by this same fix.
 *
 * Finally, this file includes a light regression check (not new proof
 * surface) confirming SuppressionService::isSuppressed() — a READ, not
 * a writer, deliberately left unwrapped by this checkpoint's production
 * change — still functions correctly now that notification_events is
 * FORCE-protected, because its only live call chain
 * (NotificationEligibilityService::check() ->
 * DocumentChaseService::checkAndLog()) already wraps its entire body in
 * an outer runWithFirmContext() call established at Checkpoint 10.
 */
class NotificationEventsForceRlsActivationTest extends TestCase
{
    // Narrowly updated by Section 39A-5 Wave 11 (webhooks domain, the final wave of the 60-table rollout, covering webhook_deliveries, webhook_delivery_attempts, webhook_events, webhook_secrets, webhook_subscriptions) for the same reason — additive only, no existing assertion removed or weakened. Total prepared/forced count is now 113.
    // Narrowly updated by Stage B Checkpoint 3 of the FirmsBase
    // Integration Platform mission (firm_integrations, a new genuine
    // tenant-owned table with RLS prepared and FORCE-activated in the
    // same migration, 2026_09_02_020002_prepare_row_level_security_and_force_rls_on_firm_integrations_table.php)
    // for the same reason — additive only, no existing assertion
    // removed or weakened. Total prepared/forced count is now 114.
    // Narrowly updated AGAIN by Stage B Checkpoint 4 of the
    // FirmsBase Integration Platform mission (integration_credentials,
    // a new genuine tenant-owned table with RLS prepared and
    // FORCE-activated in the same migration,
    // 2026_09_03_030002_prepare_row_level_security_and_force_rls_on_integration_credentials_table.php)
    // for the same reason — additive only, no existing assertion
    // removed or weakened. Total prepared/forced count is now 115.
    // Narrowly updated AGAIN by Stage B Checkpoint 5 of the FirmsBase Integration Platform mission
    // (integration_oauth_states, a new genuine tenant-owned table with RLS prepared and
    // FORCE-activated in the same migration) for the same reason — additive only, no existing
    // assertion removed or weakened. Total prepared/forced count is now 116.
    // Narrowly updated AGAIN by Stage B Checkpoint 6 of the FirmsBase Integration Platform mission
    // (integration_sync_runs, integration_sync_items, integration_external_mappings,
    // integration_sync_cursors, integration_conflicts, and integration_outbox_events, six
    // brand-new genuine tenant-owned tables, each with RLS prepared and FORCE-activated
    // in its own combined migration) for the same reason — additive only, no existing
    // assertion removed or weakened. Total prepared/forced count is now 122.
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
        'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans',
        'payment_plan_events',
    ];

    private function dispatchService(): NotificationDispatchService
    {
        return new NotificationDispatchService(
            new NotificationTemplateService(),
            new SenderDomainVerificationService(),
            new NotificationEligibilityService(new ConsentService(), new SuppressionService()),
        );
    }

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

    public function test_notification_events_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'notification_events'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_notification_events_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'notification_events'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'notification_events must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly forty-two tables (the forty-one previously forced plus
     * notification_events) must be FORCE-enabled among ALL prepared
     * tables — no more, no less.
     */
    public function test_exactly_forty_two_prepared_tables_are_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();

        // Narrowly updated by Section 39A-3L, Checkpoint 27 (backup_restore_tests) for the same reason — additive only, no existing assertion removed or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 28 (health_checks) for the same reason — additive only, no existing assertion removed or weakened.
        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations', 'integration_credentials', 'integration_oauth_states', 'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings', 'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events', 'integration_inbound_webhook_events', 'integration_connection_health']);

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

        // Narrowly updated by Section 39A-3L, Checkpoint 26 (parties) for the same reason — additive only, no existing assertion removed or weakened.
        $this->assertSame(124, count($actuallyForced), 'Exactly forty-two prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 24 — no more, no less.');
        $this->assertSame($expectedForced, $actuallyForced);
    }

    /**
     * Uncovered/uninvolved tables must be untouched by this batch.
     */
    public function test_no_unrelated_prepared_table_became_force_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();
        // Narrowly updated by Section 39A-3L, Checkpoint 27 (backup_restore_tests) for the same reason — additive only, no existing assertion removed or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 28 (health_checks) for the same reason — additive only, no existing assertion removed or weakened.
        $forced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations', 'integration_credentials', 'integration_oauth_states', 'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings', 'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events', 'integration_inbound_webhook_events', 'integration_connection_health']);

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
             where polrelid = 'notification_events'::regclass"
        );

        $this->assertNotNull($policy, 'The notification_events tenant isolation policy must still exist.');
        $this->assertSame('notification_events_tenant_isolation', $policy->polname);
        $this->assertStringContainsString('current_setting', $policy->using_expr);
        $this->assertStringContainsString('firm_id', $policy->using_expr);
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_notification_events(): void
    {
        $firm = Firm::factory()->create();
        NotificationEvent::factory()->create(['firm_id' => $firm->id]);

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, NotificationEvent::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_notification_events(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('notification_events')->insert([
            'firm_id' => $firm->id,
            'correlation_id' => (string) Str::uuid(),
            'channel' => ConsentChannel::Email->value,
            'recipient' => 'client@example.com',
            'status' => NotificationEventStatus::Attempted->value,
            'created_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_notification_event(): void
    {
        $firmA = Firm::factory()->create();
        $eventA = $this->runWithFirmContext($firmA, fn () => NotificationEvent::factory()->create(['firm_id' => $firmA->id]));

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => NotificationEvent::query()->pluck('id')->all(),
        );

        $this->assertSame([$eventA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_notification_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->runWithFirmContext($firmA, fn () => NotificationEvent::factory()->create(['firm_id' => $firmA->id]));
        $eventB = $this->runWithFirmContext($firmB, fn () => NotificationEvent::factory()->create(['firm_id' => $firmB->id]));

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => NotificationEvent::query()->pluck('id')->all(),
        );

        $this->assertNotContains($eventB->id, $visibleIds);
    }

    public function test_valid_insert_with_correct_firm_context_succeeds(): void
    {
        $firm = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext($firm, function () use ($firm) {
            return DB::table('notification_events')->insertGetId([
                'firm_id' => $firm->id,
                'correlation_id' => (string) Str::uuid(),
                'channel' => ConsentChannel::Email->value,
                'recipient' => 'client@example.com',
                'status' => NotificationEventStatus::Attempted->value,
                'created_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_context_cannot_insert_a_notification_event_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('notification_events')->insert([
                'firm_id' => $firmB->id,
                'correlation_id' => (string) Str::uuid(),
                'channel' => ConsentChannel::Email->value,
                'recipient' => 'client@example.com',
                'status' => NotificationEventStatus::Attempted->value,
                'created_at' => now(),
            ]);
        });
    }

    public function test_firm_a_context_cannot_update_firm_b_notification_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $eventB = $this->runWithFirmContext($firmB, fn () => NotificationEvent::factory()->create([
            'firm_id' => $firmB->id,
            'status' => NotificationEventStatus::Attempted,
        ]));

        $affected = $this->runWithFirmContext($firmA, function () use ($eventB) {
            return DB::table('notification_events')->where('id', $eventB->id)->update(['status' => NotificationEventStatus::Blocked->value]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s notification_events row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => NotificationEvent::query()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(NotificationEventStatus::Attempted, $reReadAsFirmB->status);
    }

    public function test_firm_a_context_cannot_delete_firm_b_notification_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $eventB = $this->runWithFirmContext($firmB, fn () => NotificationEvent::factory()->create(['firm_id' => $firmB->id]));

        $this->runWithFirmContext($firmA, function () use ($eventB) {
            DB::table('notification_events')->where('id', $eventB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => NotificationEvent::query()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s notification_events row.');
    }

    public function test_firm_a_context_cannot_reassign_firm_b_notification_event_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $eventB = $this->runWithFirmContext($firmB, fn () => NotificationEvent::factory()->create(['firm_id' => $firmB->id]));

        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $eventB) {
            return DB::table('notification_events')->where('id', $eventB->id)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s notification_events row to itself.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => NotificationEvent::query()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame($firmB->id, $reReadAsFirmB->firm_id);
    }

    // ---------------------------------------------------------------
    // Residual gap proofs — RLS only checks this row's own firm_id,
    // never a related row's owning firm. All three of client_id,
    // matter_id, and notification_template_id are nullable,
    // independently-resolved foreign keys, so each is proven
    // separately.
    // ---------------------------------------------------------------

    public function test_a_raw_insert_can_still_reference_a_client_from_a_different_firm_at_the_raw_db_layer(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $foreignClient = $this->runWithFirmContext($otherFirm, fn () => Client::factory()->forFirm($otherFirm)->create());

        $mismatchedId = $this->runWithFirmContext($firm, function () use ($firm, $foreignClient) {
            return DB::table('notification_events')->insertGetId([
                'firm_id' => $firm->id,
                'client_id' => $foreignClient->id,
                'correlation_id' => (string) Str::uuid(),
                'channel' => ConsentChannel::Email->value,
                'recipient' => 'client@example.com',
                'status' => NotificationEventStatus::Attempted->value,
                'created_at' => now(),
            ]);
        });

        $this->assertIsInt(
            $mismatchedId,
            'RLS only checks the row\'s own firm_id — a client_id belonging to a different firm is NOT blocked by RLS; this is a documented residual gap, not a false guarantee.'
        );
    }

    public function test_a_raw_insert_can_still_reference_a_matter_from_a_different_firm_at_the_raw_db_layer(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $foreignMatter = $this->runWithFirmContext($otherFirm, fn () => Matter::factory()->forFirm($otherFirm)->create());

        $mismatchedId = $this->runWithFirmContext($firm, function () use ($firm, $foreignMatter) {
            return DB::table('notification_events')->insertGetId([
                'firm_id' => $firm->id,
                'matter_id' => $foreignMatter->id,
                'correlation_id' => (string) Str::uuid(),
                'channel' => ConsentChannel::Email->value,
                'recipient' => 'client@example.com',
                'status' => NotificationEventStatus::Attempted->value,
                'created_at' => now(),
            ]);
        });

        $this->assertIsInt(
            $mismatchedId,
            'RLS only checks the row\'s own firm_id — a matter_id belonging to a different firm is NOT blocked by RLS; this is a documented residual gap, not a false guarantee.'
        );
    }

    public function test_a_raw_insert_can_still_reference_a_notification_template_from_a_different_firm_at_the_raw_db_layer(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $foreignTemplate = NotificationTemplate::factory()->forFirm($otherFirm)->create();

        $mismatchedId = $this->runWithFirmContext($firm, function () use ($firm, $foreignTemplate) {
            return DB::table('notification_events')->insertGetId([
                'firm_id' => $firm->id,
                'notification_template_id' => $foreignTemplate->id,
                'correlation_id' => (string) Str::uuid(),
                'channel' => ConsentChannel::Email->value,
                'recipient' => 'client@example.com',
                'status' => NotificationEventStatus::Attempted->value,
                'created_at' => now(),
            ]);
        });

        $this->assertIsInt(
            $mismatchedId,
            'RLS only checks the row\'s own firm_id — a notification_template_id belonging to a different firm is NOT blocked by RLS; this is a documented residual gap, not a false guarantee.'
        );
    }

    // ---------------------------------------------------------------
    // Factory correctness proofs
    // ---------------------------------------------------------------

    /**
     * Bare factory default: a bare NotificationEvent::factory()->create()
     * must succeed even from outside any already-active tenant context
     * (the factory's context-hold create() override), and must be
     * immediately readable under its own firm's context. Unlike
     * PaymentPlanEventFactory, no relation-consistency bug is possible
     * here — notification_template_id/client_id/matter_id all default
     * to null.
     */
    public function test_notification_event_factory_default_creation_is_safe_and_immediately_readable(): void
    {
        $event = NotificationEvent::factory()->create();

        $this->assertNotNull($event->id);
        $this->assertNotNull($event->firm_id);
        $this->assertNull($event->notification_template_id);
        $this->assertNull($event->client_id);
        $this->assertNull($event->matter_id);

        $persisted = $this->runWithFirmContext(
            $event->firm_id,
            fn () => NotificationEvent::query()->find($event->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame($event->firm_id, $persisted->firm_id);
    }

    /**
     * blocked() state correctness — status and reason are persisted
     * exactly as given.
     */
    public function test_notification_event_factory_blocked_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();

        $event = $this->runWithFirmContext(
            $firm,
            fn () => NotificationEvent::factory()->blocked('no granted consent for channel email')->create(['firm_id' => $firm->id]),
        );

        $persisted = $this->runWithFirmContext($firm, fn () => NotificationEvent::query()->find($event->id));

        $this->assertNotNull($persisted);
        $this->assertSame(NotificationEventStatus::Blocked, $persisted->status);
        $this->assertSame('no granted consent for channel email', $persisted->reason);
    }

    /**
     * notification_events is append-only — multiple events sharing one
     * correlation_id is the expected (in fact required) shape of a
     * single logical notification's lifecycle.
     */
    public function test_a_firm_can_have_multiple_notification_events_simultaneously(): void
    {
        $firm = Firm::factory()->create();
        $correlationId = (string) Str::uuid();

        $this->runWithFirmContext($firm, fn () => NotificationEvent::factory()->create([
            'firm_id' => $firm->id,
            'correlation_id' => $correlationId,
            'status' => NotificationEventStatus::Attempted,
        ]));
        $this->runWithFirmContext($firm, fn () => NotificationEvent::factory()->create([
            'firm_id' => $firm->id,
            'correlation_id' => $correlationId,
            'status' => NotificationEventStatus::Sent,
        ]));

        $count = $this->runWithFirmContext($firm, fn () => NotificationEvent::query()->where('correlation_id', $correlationId)->count());

        $this->assertSame(2, $count, 'notification_events is append-only — a second event sharing one correlation_id must be a supported state.');
    }

    // ---------------------------------------------------------------
    // Writer regression proofs — the central finding of this
    // checkpoint. Each proves the corresponding method genuinely
    // persists a notification_events row to the database even when
    // called with NO ambient tenant context established beforehand,
    // and even though NONE of these five methods has a live
    // production caller today — each is called DIRECTLY, since there
    // is no real business-flow entry point to exercise instead.
    // ---------------------------------------------------------------

    public function test_dispatch_persists_an_attempted_and_blocked_event_pair_when_no_template_resolves_with_no_ambient_context_established_beforehand(): void
    {
        Queue::fake();
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        (new TenantContextService())->clearDatabaseTenantContext();
        (new TenantContextService())->clearFirmContext();
        $this->assertNoDatabaseTenantContext();

        $service = $this->dispatchService();
        $result = $service->dispatch($firm, $client, ConsentChannel::Email, $client->email, 'nonexistent_key');

        $this->assertNoDatabaseTenantContext('dispatch() must clear its own internal context wrap before returning.');
        $this->assertFalse($result->accepted);
        $this->assertSame(NotificationEventStatus::Blocked, $result->status);

        $events = $this->runWithFirmContext(
            $firm,
            fn () => NotificationEvent::query()->where('firm_id', $firm->id)->pluck('status')->all(),
        );

        $this->assertContains(NotificationEventStatus::Attempted, $events, 'dispatch() must genuinely persist its Attempted notification_events row to the database, not just an in-memory side effect.');
        $this->assertContains(NotificationEventStatus::Blocked, $events, 'dispatch() must genuinely persist its Blocked notification_events row to the database.');
    }

    public function test_dispatch_persists_a_blocked_event_when_the_sender_domain_is_unverified_with_no_ambient_context_established_beforehand(): void
    {
        Queue::fake();
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, function () use ($firm, $client) {
            CommunicationConsent::factory()->create([
                'firm_id' => $firm->id,
                'client_id' => $client->id,
                'channel' => ConsentChannel::Email,
                'status' => ConsentStatus::Granted,
                'granted_at' => now(),
            ]);
        });
        NotificationTemplate::factory()->domainUnverified()->create([
            'firm_id' => null,
            'key' => 'document_reminder',
            'channel' => ConsentChannel::Email,
            'status' => NotificationTemplateStatus::Active,
        ]);

        (new TenantContextService())->clearDatabaseTenantContext();
        (new TenantContextService())->clearFirmContext();
        $this->assertNoDatabaseTenantContext();

        $service = $this->dispatchService();
        $result = $service->dispatch($firm, $client, ConsentChannel::Email, $client->email, 'document_reminder');

        $this->assertNoDatabaseTenantContext('dispatch() must clear its own internal context wrap before returning.');
        $this->assertFalse($result->accepted);
        $this->assertStringContainsString('sender domain not verified', $result->reason);

        $blockedEvent = $this->runWithFirmContext(
            $firm,
            fn () => NotificationEvent::query()->where('firm_id', $firm->id)->where('status', NotificationEventStatus::Blocked->value)->first(),
        );

        $this->assertNotNull($blockedEvent, 'dispatch() must genuinely persist its sender-domain-unverified Blocked notification_events row to the database.');
        $this->assertStringContainsString('sender domain not verified', $blockedEvent->reason);
    }

    public function test_dispatch_persists_a_blocked_event_when_eligibility_fails_with_no_ambient_context_established_beforehand(): void
    {
        Queue::fake();
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        // No consent granted -> eligibility fails -> Blocked (not Suppressed).
        NotificationTemplate::factory()->domainVerified()->create([
            'firm_id' => null,
            'key' => 'document_reminder',
            'channel' => ConsentChannel::Email,
            'status' => NotificationTemplateStatus::Active,
        ]);

        (new TenantContextService())->clearDatabaseTenantContext();
        (new TenantContextService())->clearFirmContext();
        $this->assertNoDatabaseTenantContext();

        $service = $this->dispatchService();
        $result = $service->dispatch($firm, $client, ConsentChannel::Email, $client->email, 'document_reminder');

        $this->assertNoDatabaseTenantContext('dispatch() must clear its own internal context wrap before returning.');
        $this->assertFalse($result->accepted);
        $this->assertSame(NotificationEventStatus::Blocked, $result->status);

        $blockedEvent = $this->runWithFirmContext(
            $firm,
            fn () => NotificationEvent::query()->where('firm_id', $firm->id)->where('status', NotificationEventStatus::Blocked->value)->first(),
        );

        $this->assertNotNull($blockedEvent, 'dispatch() must genuinely persist its eligibility-failure Blocked notification_events row to the database.');
    }

    /**
     * CORRECTED as of this checkpoint's follow-up fix (already applied
     * in app/Services/NotificationDispatchService.php — see its own
     * class docblock, and this file's own class docblock above for the
     * full before/after account): dispatch() now wraps its ENTIRE body
     * — including all four internal recordEvent() call sites and the
     * eligibility gate — in a single runWithFirmContext($firm, ...)
     * call, established from its own $firm parameter and held for its
     * entire execution. It no longer depends on any ambient/leaked
     * context from a caller or a factory's context-hold override, and
     * it no longer fails closed when called with zero pre-existing
     * context, because it doesn't need pre-existing context at all
     * anymore.
     *
     * This positively proves dispatch() genuinely reaches
     * accepted=true/Queued when every gate legitimately passes: a real
     * template resolves, its sender domain is verified, and a real
     * GRANTED consent exists. Setup below uses bare/unwrapped factory
     * calls (no `runWithFirmContext` wrap around any of it), proving
     * dispatch() no longer needs the caller to establish or leak any
     * context — it is fully self-sufficient. Ambient context is also
     * explicitly cleared and asserted absent immediately before calling
     * dispatch(), to prove self-sufficiency directly rather than merely
     * assuming no context happened to leak from setup. Matches
     * tests/Feature/Notifications/NotificationDispatchServiceTest.php::
     * test_dispatch_succeeds_and_queues_a_job_when_every_gate_passes,
     * which is verified passing again by this same fix.
     */
    public function test_dispatch_succeeds_and_persists_a_queued_event_and_queues_a_job_with_zero_ambient_context_established_beforehand(): void
    {
        Queue::fake();
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        // Bare, unwrapped factory calls — proving dispatch() no longer
        // needs the caller to establish or leak any context; it
        // establishes and holds its own context internally now.
        CommunicationConsent::factory()->create([
            'firm_id' => $firm->id,
            'client_id' => $client->id,
            'channel' => ConsentChannel::Email,
            'status' => ConsentStatus::Granted,
            'granted_at' => now(),
        ]);
        NotificationTemplate::factory()->domainVerified()->create([
            'firm_id' => null,
            'key' => 'document_reminder',
            'channel' => ConsentChannel::Email,
            'status' => NotificationTemplateStatus::Active,
        ]);

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $service = $this->dispatchService();
        $result = $service->dispatch($firm, $client, ConsentChannel::Email, $client->email, 'document_reminder');

        $this->assertNoDatabaseTenantContext('dispatch() must clear its own internal context wrap before returning.');
        $this->assertTrue(
            $result->accepted,
            'dispatch() must now genuinely succeed when every gate passes, even with zero ambient context established beforehand — it establishes its own context from its own $firm parameter and holds it for its entire execution.'
        );
        $this->assertSame(NotificationEventStatus::Queued, $result->status);

        $queuedEvent = $this->runWithFirmContext(
            $firm,
            fn () => NotificationEvent::query()->where('firm_id', $firm->id)->where('status', NotificationEventStatus::Queued->value)->first(),
        );
        $this->assertNotNull($queuedEvent, 'dispatch() must genuinely persist its Queued notification_events row to the database, not just an in-memory side effect.');

        Queue::assertPushed(\App\Jobs\DispatchNotificationJob::class);
    }

    public function test_record_sent_persists_a_notification_event_when_called_with_no_ambient_context_established_beforehand(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();
        (new TenantContextService())->clearFirmContext();
        $this->assertNoDatabaseTenantContext();

        $service = $this->dispatchService();
        $correlationId = (string) Str::uuid();
        $event = $service->recordSent($firm, $correlationId, ConsentChannel::Email, 'client@example.com', null, null, null);

        $this->assertNoDatabaseTenantContext('recordSent() must clear its own internal context wrap before returning.');
        $this->assertNotNull($event->id);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => NotificationEvent::query()->where('correlation_id', $correlationId)->where('status', NotificationEventStatus::Sent->value)->first(),
        );

        $this->assertNotNull($persisted, 'recordSent() must genuinely persist its notification_events row to the database, not just an in-memory side effect.');
        $this->assertSame($firm->id, $persisted->firm_id);
    }

    public function test_record_failed_persists_a_notification_event_when_called_with_no_ambient_context_established_beforehand(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();
        (new TenantContextService())->clearFirmContext();
        $this->assertNoDatabaseTenantContext();

        $service = $this->dispatchService();
        $correlationId = (string) Str::uuid();
        $event = $service->recordFailed($firm, $correlationId, ConsentChannel::Email, 'client@example.com', null, 'transport error');

        $this->assertNoDatabaseTenantContext('recordFailed() must clear its own internal context wrap before returning.');
        $this->assertNotNull($event->id);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => NotificationEvent::query()->where('correlation_id', $correlationId)->where('status', NotificationEventStatus::Failed->value)->first(),
        );

        $this->assertNotNull($persisted, 'recordFailed() must genuinely persist its notification_events row to the database.');
        $this->assertSame($firm->id, $persisted->firm_id);
        $this->assertSame('transport error', $persisted->reason);
    }

    public function test_record_bounce_persists_a_notification_event_when_called_with_no_ambient_context_established_beforehand(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();
        (new TenantContextService())->clearFirmContext();
        $this->assertNoDatabaseTenantContext();

        $service = new SuppressionService();
        $correlationId = (string) Str::uuid();
        $event = $service->recordBounce($firm, 'client@example.com', ConsentChannel::Email, $correlationId, 'hard bounce');

        $this->assertNoDatabaseTenantContext('recordBounce() must clear its own internal context wrap before returning.');
        $this->assertNotNull($event->id);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => NotificationEvent::query()->where('correlation_id', $correlationId)->where('status', NotificationEventStatus::Bounced->value)->first(),
        );

        $this->assertNotNull($persisted, 'recordBounce() must genuinely persist its notification_events row to the database.');
        $this->assertSame($firm->id, $persisted->firm_id);
    }

    public function test_record_complaint_persists_a_notification_event_when_called_with_no_ambient_context_established_beforehand(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();
        (new TenantContextService())->clearFirmContext();
        $this->assertNoDatabaseTenantContext();

        $service = new SuppressionService();
        $correlationId = (string) Str::uuid();
        $event = $service->recordComplaint($firm, 'client@example.com', ConsentChannel::Sms, $correlationId, 'spam complaint');

        $this->assertNoDatabaseTenantContext('recordComplaint() must clear its own internal context wrap before returning.');
        $this->assertNotNull($event->id);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => NotificationEvent::query()->where('correlation_id', $correlationId)->where('status', NotificationEventStatus::Complained->value)->first(),
        );

        $this->assertNotNull($persisted, 'recordComplaint() must genuinely persist its notification_events row to the database.');
        $this->assertSame($firm->id, $persisted->firm_id);
    }

    // ---------------------------------------------------------------
    // isSuppressed() regression check — a light proof, not new
    // surface: SuppressionService::isSuppressed() was deliberately
    // left unwrapped by this checkpoint's production change, because
    // its only live call chain (NotificationEligibilityService::
    // check() -> DocumentChaseService::checkAndLog()) already wraps
    // its entire body in an outer runWithFirmContext() call
    // established at Checkpoint 10. This proves that chain still
    // correctly reflects notification_events state now that the table
    // is FORCE-protected.
    // ---------------------------------------------------------------

    public function test_is_suppressed_read_through_document_chase_service_check_and_log_still_works_correctly_after_force_activation(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        // Grant consent so the ONLY remaining eligibility gate is
        // suppression.
        $this->runWithFirmContext($firm, function () use ($firm, $client) {
            CommunicationConsent::factory()->create([
                'firm_id' => $firm->id,
                'client_id' => $client->id,
                'channel' => ConsentChannel::Email,
                'status' => ConsentStatus::Granted,
                'granted_at' => now(),
            ]);
        });

        // Record a prior bounce for this exact recipient/channel via
        // the real writer (also proved independently above) — this IS
        // the suppression list, per SuppressionService's own class
        // docblock.
        (new SuppressionService())->recordBounce($firm, $client->email, ConsentChannel::Email, (string) Str::uuid());

        $request = $this->runWithFirmContext($firm, fn () => DocumentRequest::factory()->create(['firm_id' => $firm->id, 'client_id' => $client->id]));
        $item = $this->runWithFirmContext($firm, fn () => DocumentRequestItem::factory()->create([
            'document_request_id' => $request->id,
            'status' => DocumentRequestItemStatus::Requested,
        ]));

        $chaseService = new DocumentChaseService(
            new NotificationEligibilityService(new ConsentService(), new SuppressionService()),
            new TimelineEventRecorder(),
        );

        // checkAndLog() establishes its OWN ambient context internally
        // — no context set up by this test beforehand.
        (new TenantContextService())->clearDatabaseTenantContext();
        (new TenantContextService())->clearFirmContext();
        $this->assertNoDatabaseTenantContext();

        $result = $chaseService->checkAndLog($firm, $item);

        $this->assertNoDatabaseTenantContext('checkAndLog() must clear its own internal context wrap before returning.');
        $this->assertFalse($result->eligible, 'isSuppressed() must still correctly detect the prior bounce and report the recipient as ineligible, even though notification_events is now FORCE-protected.');
        $this->assertStringContainsString('suppressed', (string) $result->reason);

        $skippedEvent = $this->runWithFirmContext(
            $firm,
            fn () => $item->chaseEvents()->where('event_type', 'reminder_skipped')->first(),
        );
        $this->assertNotNull($skippedEvent, 'checkAndLog() must genuinely log the reminder_skipped outcome once isSuppressed() correctly detects the suppression.');
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => NotificationEvent::factory()->create(['firm_id' => $firm->id]));

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
     * Forty-one previously forced tables plus notification_events must
     * be independently force-active and independently isolated at the
     * same time — proof this batch did not weaken or interfere with
     * any prior section's own enforcement. Uses payment_plan_events as
     * the companion table (forced immediately prior, at Checkpoint 23).
     */
    public function test_notification_events_are_isolated_independently_and_simultaneously_with_payment_plan_events(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $planA = $this->runWithFirmContext($firmA, fn () => PaymentPlan::factory()->forFirm($firmA)->create());
        $planB = $this->runWithFirmContext($firmB, fn () => PaymentPlan::factory()->forFirm($firmB)->create());

        $planEventA = $this->runWithFirmContext($firmA, fn () => PaymentPlanEvent::factory()->forPlan($planA)->create());
        $planEventB = $this->runWithFirmContext($firmB, fn () => PaymentPlanEvent::factory()->forPlan($planB)->create());

        $notificationEventA = $this->runWithFirmContext($firmA, fn () => NotificationEvent::factory()->create(['firm_id' => $firmA->id]));
        $notificationEventB = $this->runWithFirmContext($firmB, fn () => NotificationEvent::factory()->create(['firm_id' => $firmB->id]));

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'notification_events' => NotificationEvent::query()->pluck('id')->all(),
            'payment_plan_events' => PaymentPlanEvent::query()->pluck('id')->all(),
        ]);

        $this->assertSame([$notificationEventA->id], $resultA['notification_events']);
        $this->assertNotContains($notificationEventB->id, $resultA['notification_events']);
        $this->assertSame([$planEventA->id], $resultA['payment_plan_events']);
        $this->assertNotContains($planEventB->id, $resultA['payment_plan_events']);
    }

    // ---------------------------------------------------------------
    // Migration down()/up() restoration proofs
    // ---------------------------------------------------------------

    /**
     * Rollback support: the notification_events migration's down()
     * must genuinely restore the Section 39A baseline — RLS still
     * enabled, policy still present, but NOT forced — never drop the
     * policy or disable RLS itself. Also proves rollback affects ONLY
     * this one table — every other previously-forced table must be
     * untouched.
     */
    public function test_notification_events_migration_down_restores_the_not_forced_baseline_and_affects_only_this_table(): void
    {
        $migration = require base_path('database/migrations/2026_08_25_930024_force_rls_on_notification_events_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'notification_events'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while notification_events is rolled back."
                );
            }

            $policy = DB::selectOne(
                "select polname from pg_policy where polrelid = 'notification_events'::regclass"
            );
            $this->assertNotNull($policy, 'Rollback must not drop the tenant isolation policy.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'notification_events'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }
}
