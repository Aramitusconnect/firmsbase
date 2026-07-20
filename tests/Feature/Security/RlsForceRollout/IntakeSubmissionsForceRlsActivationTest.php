<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\IntakeSubmissionStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\IntakeSubmission;
use App\Models\IntakeTemplate;
use App\Services\CalendarEventService;
use App\Services\ComplianceGapRegistryService;
use App\Services\ConflictCheckService;
use App\Services\DeadlineService;
use App\Services\DocumentRequestService;
use App\Services\DocumentSecurityService;
use App\Services\DocumentUploadPolicyService;
use App\Services\EmployeeRateService;
use App\Services\InvoiceDraftingService;
use App\Services\LeadConversionService;
use App\Services\ManualPaymentService;
use App\Services\MatterOpeningService;
use App\Services\MatterReadinessService;
use App\Services\PaymentApplicationService;
use App\Services\PaymentClassificationService;
use App\Services\PaymentPlanService;
use App\Services\ProductionPilotWorkflowService;
use App\Services\ReadinessScorecardRegistry;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use App\Services\TimeEntryApprovalService;
use App\Services\TimelineEventRecorder;
use App\Services\VirusScan\FakeVirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * IntakeSubmissionsForceRlsActivationTest — Section 39A-3L, Checkpoint
 * 13. Proves the thirty-first staged FORCE ROW LEVEL SECURITY
 * activation batch
 * (database/migrations/2026_08_25_930013_force_rls_on_intake_submissions_table.php)
 * is permanently active for intake_submissions and behaves correctly:
 * fail-closed with no context, correct cross-firm isolation, correct
 * same-firm access, that every previously-forced table remains forced
 * simultaneously, and that
 * ProductionPilotWorkflowService::submitIntake() (now wrapped in full —
 * create(), update(), and fresh() all inside a single
 * runWithFirmContext() call) functions correctly end-to-end under
 * FORCE.
 *
 * Known, explicitly NOT fixed in this batch (tracked separately, see
 * the migration's own docblock): no composite foreign key validates
 * that client_id's or matter_id's owning firm matches
 * intake_submissions.firm_id. FORCE RLS does not catch this (RLS only
 * checks this table's own firm_id column, never a related row's
 * firm_id), so a cross-firm client_id/matter_id reference remains
 * theoretically possible at the database layer if application code
 * ever bypassed the established write path. See
 * test_a_raw_insert_can_still_reference_a_client_from_a_different_firm_at_the_raw_db_layer
 * below for the honest, empirically-proven boundary of that claim —
 * documented as a residual database-constraint gap, not something RLS
 * itself closes, and not a false guarantee.
 */
class IntakeSubmissionsForceRlsActivationTest extends TestCase
{
    // Narrowly updated by Section 39A-5 Wave 11 (webhooks domain, the final wave of the 60-table rollout, covering webhook_deliveries, webhook_delivery_attempts, webhook_events, webhook_secrets, webhook_subscriptions) for the same reason — additive only, no existing assertion removed or weakened. Total prepared/forced count is now 113.
    // Narrowly updated by Stage B Checkpoint 3 of the FirmsBase
    // Integration Platform mission (firm_integrations, a new genuine
    // tenant-owned table with RLS prepared and FORCE-activated in the
    // same migration, 2026_09_02_020002_prepare_row_level_security_and_force_rls_on_firm_integrations_table.php)
    // for the same reason — additive only, no existing assertion
    // removed or weakened. Total prepared/forced count is now 114.
    use RefreshDatabase;

    private const PREVIOUSLY_FORCED_TABLES = [
        'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments',
        'conflict_check_runs', 'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
        'firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events',
        'client_communication_preferences', 'payment_classification_events', 'activation_checklists',
        'firm_activation_events', 'firm_entitlements', 'firm_entitlement_events', 'installed_template_packs',
        'template_upgrade_logs', 'template_upgrade_previews', 'seat_allocations', 'document_requests',
        'communication_consents', 'communication_consent_events',
    ];

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

    public function test_intake_submissions_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'intake_submissions'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_intake_submissions_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'intake_submissions'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'intake_submissions must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly thirty-one tables (the thirty previously forced plus
     * intake_submissions) must be FORCE-enabled among ALL prepared
     * tables — no more, no less.
     */
    public function test_exactly_thirty_one_prepared_tables_are_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();

        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 14,
        // Table Phase C (this repo's thirty-second staged FORCE
        // activation batch, covering matter_readiness_scores) for
        // the same reason — additive only, no existing assertion
        // removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 15,
        // Table Phase C (this repo's thirty-third staged FORCE
        // activation batch, covering readiness_score_events) for
        // the same reason — additive only, no existing assertion
        // removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 16,
        // Table Phase C (this repo's thirty-fourth staged FORCE
        // activation batch, covering tenant_encryption_keys) for
        // the same reason — additive only, no existing assertion
        // removed or weakened.
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
        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'intake_submissions', 'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events', 'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans', 'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations']);
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
        $this->assertSame(114, count($actuallyForced), 'Exactly thirty-six prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 13 — no more, no less. Narrowly updated again for Section 39A-3L, Checkpoint 14 (matter_readiness_scores added on top of the prior thirty-one), again for Checkpoint 15 (readiness_score_events added on top of the prior thirty-two), and again for Checkpoint 16 (tenant_encryption_keys added on top of the prior thirty-three).');
        $this->assertSame($expectedForced, $actuallyForced);
    }

    /**
     * Uncovered/uninvolved tables must be untouched by this batch.
     */
    public function test_no_unrelated_prepared_table_became_force_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 14,
        // Table Phase C (this repo's thirty-second staged FORCE
        // activation batch, covering matter_readiness_scores) for
        // the same reason — additive only, no existing assertion
        // removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 15,
        // Table Phase C (this repo's thirty-third staged FORCE
        // activation batch, covering readiness_score_events) for
        // the same reason — additive only, no existing assertion
        // removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 16,
        // Table Phase C (this repo's thirty-fourth staged FORCE
        // activation batch, covering tenant_encryption_keys) for
        // the same reason — additive only, no existing assertion
        // removed or weakened.
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
        $forced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'intake_submissions', 'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events', 'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans', 'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations']);
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

    public function test_the_tenant_isolation_policy_remains_present_and_unchanged_after_up(): void
    {
        $policy = DB::selectOne(
            "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'intake_submissions'::regclass"
        );

        $this->assertNotNull($policy, 'The intake_submissions tenant isolation policy must still exist.');
        $this->assertStringContainsString('current_setting', $policy->using_expr);
        $this->assertStringContainsString('firm_id', $policy->using_expr);
    }

    /**
     * Genuine no-context regression proof: explicitly clears
     * app.current_firm_id immediately before reading — proving the
     * read genuinely fails closed now that this table is forced.
     */
    public function test_missing_tenant_context_cannot_read_intake_submissions(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $template = IntakeTemplate::factory()->create();

        $this->runWithFirmContext($firm, fn () => IntakeSubmission::factory()->forClient($client)->create([
            'intake_template_id' => $template->id,
        ]));

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, IntakeSubmission::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_insert_intake_submissions(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $template = IntakeTemplate::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('intake_submissions')->insert([
            'firm_id' => $firm->id,
            'client_id' => $client->id,
            'matter_id' => null,
            'intake_template_id' => $template->id,
            'status' => 'draft',
            'responses_json' => json_encode([]),
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_firm_a_context_can_read_its_own_intake_submission(): void
    {
        $firmA = Firm::factory()->create();
        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $template = IntakeTemplate::factory()->create();

        $submissionA = $this->runWithFirmContext($firmA, fn () => IntakeSubmission::factory()->forClient($clientA)->create([
            'intake_template_id' => $template->id,
        ]));

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => IntakeSubmission::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$submissionA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_intake_submission(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());
        $template = IntakeTemplate::factory()->create();

        $this->runWithFirmContext($firmA, fn () => IntakeSubmission::factory()->forClient($clientA)->create([
            'intake_template_id' => $template->id,
        ]));
        $submissionB = $this->runWithFirmContext($firmB, fn () => IntakeSubmission::factory()->forClient($clientB)->create([
            'intake_template_id' => $template->id,
        ]));

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => IntakeSubmission::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($submissionB->id, $visibleIds);
    }

    public function test_valid_insert_with_correct_firm_context_succeeds(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $template = IntakeTemplate::factory()->create();

        $insertedId = $this->runWithFirmContext($firm, function () use ($firm, $client, $template) {
            return DB::table('intake_submissions')->insertGetId([
                'firm_id' => $firm->id,
                'client_id' => $client->id,
                'matter_id' => null,
                'intake_template_id' => $template->id,
                'status' => 'draft',
                'responses_json' => json_encode([]),
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_context_cannot_insert_an_intake_submission_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());
        $template = IntakeTemplate::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $clientB, $template) {
            DB::table('intake_submissions')->insert([
                'firm_id' => $firmB->id,
                'client_id' => $clientB->id,
                'matter_id' => null,
                'intake_template_id' => $template->id,
                'status' => 'draft',
                'responses_json' => json_encode([]),
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_firm_a_context_cannot_update_firm_b_intake_submission(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());
        $template = IntakeTemplate::factory()->create();
        $submissionB = $this->runWithFirmContext($firmB, fn () => IntakeSubmission::factory()->forClient($clientB)->create([
            'intake_template_id' => $template->id,
        ]));

        $this->runWithFirmContext($firmA, function () use ($submissionB) {
            DB::table('intake_submissions')->where('id', $submissionB->id)->update(['status' => 'reviewed']);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => IntakeSubmission::withoutGlobalScopes()->find($submissionB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(
            $submissionB->status,
            $reReadAsFirmB->status,
            'Firm A context must not be able to update Firm B\'s intake_submissions row.'
        );
    }

    public function test_firm_a_context_cannot_delete_firm_b_intake_submission(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());
        $template = IntakeTemplate::factory()->create();
        $submissionB = $this->runWithFirmContext($firmB, fn () => IntakeSubmission::factory()->forClient($clientB)->create([
            'intake_template_id' => $template->id,
        ]));

        $this->runWithFirmContext($firmA, function () use ($submissionB) {
            DB::table('intake_submissions')->where('id', $submissionB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => IntakeSubmission::withoutGlobalScopes()->find($submissionB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s intake_submissions row.');
    }

    /**
     * Firm ownership itself (the firm_id column) must never be
     * reassignable via a raw UPDATE while under a different firm's
     * context.
     */
    public function test_firm_a_context_cannot_reassign_firm_b_intake_submission_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());
        $template = IntakeTemplate::factory()->create();
        $submissionB = $this->runWithFirmContext($firmB, fn () => IntakeSubmission::factory()->forClient($clientB)->create([
            'intake_template_id' => $template->id,
        ]));

        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $submissionB) {
            return DB::table('intake_submissions')->where('id', $submissionB->id)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s intake submission to itself.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => IntakeSubmission::withoutGlobalScopes()->find($submissionB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame($firmB->id, $reReadAsFirmB->firm_id);
    }

    /**
     * Empirically proves the honest scope boundary described in this
     * file's class docblock and the migration's own docblock: RLS only
     * validates intake_submissions.firm_id, never client_id's OWN
     * firm_id — a raw insert whose firm_id matches the active context
     * still succeeds even when client_id points at a Client belonging
     * to a COMPLETELY DIFFERENT firm. This is a documented residual
     * DATABASE-CONSTRAINT gap, not something RLS itself closes — never
     * to be described as blocked.
     */
    public function test_a_raw_insert_can_still_reference_a_client_from_a_different_firm_at_the_raw_db_layer(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $foreignClient = $this->runWithFirmContext($otherFirm, fn () => Client::factory()->forFirm($otherFirm)->create());
        $template = IntakeTemplate::factory()->create();

        $mismatchedId = $this->runWithFirmContext($firm, function () use ($firm, $foreignClient, $template) {
            return DB::table('intake_submissions')->insertGetId([
                'firm_id' => $firm->id,
                'client_id' => $foreignClient->id,
                'matter_id' => null,
                'intake_template_id' => $template->id,
                'status' => 'draft',
                'responses_json' => json_encode([]),
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt(
            $mismatchedId,
            'RLS only checks the row\'s own firm_id — a client_id belonging to a different firm is NOT blocked by RLS; this is a documented residual gap, not a false guarantee.'
        );
    }

    /**
     * Bare factory default: a bare IntakeSubmission::factory()->create()
     * must succeed even from outside any already-active tenant context
     * (the factory's context-hold create() override), and the row must
     * actually be visible/readable under its own firm's context
     * afterward. Also proves the Checkpoint 13 root-cause fix: firm_id
     * and client_id are derived from the SAME Client, so there is no
     * cross-firm mismatch even on a bare default.
     */
    public function test_intake_submission_factory_default_creation_is_internally_consistent(): void
    {
        $submission = IntakeSubmission::factory()->create();

        $this->assertNotNull($submission->id);
        $this->assertNotNull($submission->firm_id);
        $this->assertNotNull($submission->client_id);

        $persisted = $this->runWithFirmContext(
            $submission->firm,
            fn () => IntakeSubmission::withoutGlobalScopes()->find($submission->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame($submission->firm_id, $persisted->firm_id);

        $clientFirmId = $this->runWithFirmContext(
            $submission->firm,
            fn () => Client::withoutGlobalScopes()->find($submission->client_id)?->firm_id,
        );

        $this->assertSame(
            $submission->firm_id,
            $clientFirmId,
            'The bare factory default must derive firm_id and client_id from the SAME Client — no cross-firm mismatch.'
        );
    }

    /**
     * Explicit related-model factory state correctness: forClient()
     * must set firm_id/client_id to the EXACT client given, and the row
     * must be readable only under that firm's context.
     */
    public function test_intake_submission_factory_for_client_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        $submission = $this->runWithFirmContext($firm, fn () => IntakeSubmission::factory()->forClient($client)->create());

        $this->assertSame($firm->id, $submission->firm_id);
        $this->assertSame($client->id, $submission->client_id);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => IntakeSubmission::withoutGlobalScopes()->find($submission->id),
        );

        $this->assertNotNull($persisted);
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, fn () => IntakeSubmission::factory()->forClient($client)->create());

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

    /**
     * End-to-end proof that ProductionPilotWorkflowService::
     * submitIntake() functions correctly under FORCE — the entire
     * method body (create(), update(), fresh()) is wrapped in a single
     * runWithFirmContext() call, and the final returned submission must
     * be non-null and carry the submitted responses.
     */
    public function test_the_submit_intake_flow_persists_a_submitted_intake_submission_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $template = IntakeTemplate::factory()->create();

        // Constructed manually (matching
        // ProductionPilotWorkflowServiceTest's own established pattern)
        // rather than resolved via the container, so this test never
        // depends on the real VirusScanner binding — submitIntake()
        // never touches it, but every other constructor argument is a
        // real Phase 1-4 service.
        $timeline = new TimelineEventRecorder();
        $paymentPlanService = new PaymentPlanService($timeline);
        $invoices = new InvoiceDraftingService(new TimeEntryApprovalService(new EmployeeRateService()), $timeline);

        $service = new ProductionPilotWorkflowService(
            new LeadConversionService($timeline),
            new MatterOpeningService(new ConflictCheckService($timeline), $timeline),
            new DocumentRequestService(),
            new DocumentSecurityService(new DocumentUploadPolicyService()),
            new FakeVirusScanner(),
            new DeadlineService(new CalendarEventService()),
            $invoices,
            $paymentPlanService,
            new ManualPaymentService(
                new PaymentClassificationService(),
                new PaymentApplicationService($paymentPlanService, $timeline),
                $timeline,
            ),
            new MatterReadinessService(new ReadinessScorecardRegistry()),
        );

        $intake = $service->submitIntake($firm, $client, $template, ['immigration_status' => 'H-1B']);

        $this->assertNoDatabaseTenantContext('submitIntake() must clear its own context wrap before returning.');
        $this->assertNotNull($intake);
        $this->assertSame(IntakeSubmissionStatus::Submitted, $intake->status);
        $this->assertSame(['immigration_status' => 'H-1B'], $intake->responses_json);
        $this->assertNotNull($intake->submitted_at);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => IntakeSubmission::withoutGlobalScopes()->find($intake->id),
        );

        $this->assertNotNull($persisted, 'submitIntake() must persist exactly one intake_submissions row under FORCE, readable under its own firm context.');
        $this->assertSame(IntakeSubmissionStatus::Submitted, $persisted->status);
        $this->assertSame($firm->id, $persisted->firm_id);
        $this->assertSame($client->id, $persisted->client_id);
    }

    public function test_compliance_gap_registry_service_still_tracks_the_rls_gap(): void
    {
        $registry = new ComplianceGapRegistryService();

        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
    }

    /**
     * Thirty previously forced tables plus intake_submissions must be
     * independently force-active and independently isolated at the
     * same time — proof this batch did not weaken or interfere with
     * any prior section's own enforcement. Uses clients as the
     * companion table.
     */
    public function test_intake_submissions_is_isolated_independently_and_simultaneously_with_clients(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());

        $submissionA = $this->runWithFirmContext($firmA, fn () => IntakeSubmission::factory()->forClient($clientA)->create());
        $submissionB = $this->runWithFirmContext($firmB, fn () => IntakeSubmission::factory()->forClient($clientB)->create());

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'intake_submissions' => IntakeSubmission::withoutGlobalScopes()->pluck('id')->all(),
            'clients' => Client::withoutGlobalScopes()->pluck('id')->all(),
        ]);

        $this->assertSame([$submissionA->id], $resultA['intake_submissions']);
        $this->assertNotContains($submissionB->id, $resultA['intake_submissions']);
        $this->assertContains($clientA->id, $resultA['clients']);
        $this->assertNotContains($clientB->id, $resultA['clients']);
    }

    /**
     * Rollback support: the migration's down() must genuinely restore
     * the Section 39A baseline — RLS still enabled, policy still
     * present, but NOT forced — never drop the policy or disable RLS
     * itself. Also proves rollback affects ONLY this one table — every
     * other previously-forced table must be untouched.
     */
    public function test_migration_down_restores_the_not_forced_baseline_and_affects_only_this_table(): void
    {
        $migration = require base_path('database/migrations/2026_08_25_930013_force_rls_on_intake_submissions_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'intake_submissions'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while intake_submissions is rolled back."
                );
            }

            $policy = DB::selectOne(
                "select polname from pg_policy where polrelid = 'intake_submissions'::regclass"
            );
            $this->assertNotNull($policy, 'Rollback must not drop the tenant isolation policy.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'intake_submissions'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }
}
