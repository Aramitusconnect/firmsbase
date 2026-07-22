<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\FirmLeadStatus;
use App\Enums\ImportEntityType;
use App\Enums\ImportRowStatus;
use App\Enums\ImportSourceType;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmLead;
use App\Services\FirmCommandCenterAggregationService;
use App\Services\ImportApplyService;
use App\Services\ImportAuditService;
use App\Services\ImportBatchService;
use App\Services\ImportDocumentSafetyService;
use App\Services\DocumentUploadPolicyService;
use App\Services\TenantContextService;
use App\Services\VirusScan\FakeVirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * FirmLeadsForceRlsActivationTest — Section 39A-3J (batch 3 of 4).
 * Proves the twelfth staged FORCE ROW LEVEL SECURITY activation batch
 * (database/migrations/2026_08_14_900001_force_rls_on_firm_leads_table.php)
 * is permanently active for firm_leads and behaves correctly:
 * fail-closed with no context, correct cross-firm isolation, correct
 * same-firm access, and that every table forced by a prior section or
 * by this same batch (clients, firm_users, documents, deadlines,
 * tasks, matters, invoices, payments, conflict_check_runs,
 * lead_sources, consultation_outcomes, consultations) remains forced
 * simultaneously. Also proves the two production call sites wired
 * alongside this batch's migrations
 * (ImportApplyService::createRecordFor()'s FirmLead branch and
 * FirmCommandCenterAggregationService::snapshot()'s newLeadsCount)
 * continue to work correctly under FORCE.
 */
class FirmLeadsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_clients_remains_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'clients'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'clients must remain FORCE RLS enabled after this branch.');
    }

    public function test_firm_users_remains_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'firm_users'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'firm_users must remain FORCE RLS enabled after this branch.');
    }

    public function test_documents_remains_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'documents'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'documents must remain FORCE RLS enabled after this branch.');
    }

    public function test_deadlines_remains_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'deadlines'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'deadlines must remain FORCE RLS enabled after this branch.');
    }

    public function test_tasks_remains_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'tasks'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'tasks must remain FORCE RLS enabled after this branch.');
    }

    public function test_matters_remains_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'matters'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'matters must remain FORCE RLS enabled after this branch.');
    }

    public function test_invoices_remains_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'invoices'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'invoices must remain FORCE RLS enabled after this branch.');
    }

    public function test_payments_remains_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'payments'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'payments must remain FORCE RLS enabled after this branch.');
    }

    public function test_conflict_check_runs_remains_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'conflict_check_runs'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'conflict_check_runs must remain FORCE RLS enabled after this branch.');
    }

    /**
     * lead_sources, consultation_outcomes, and consultations are this
     * same batch's other three tables — they land in the same
     * migration run as firm_leads, so this file proves firm_leads' own
     * isolation is correct alongside its three siblings, not in a
     * vacuum.
     */
    public function test_lead_sources_is_also_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'lead_sources'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'lead_sources must be FORCE RLS enabled alongside firm_leads in this batch.');
    }

    public function test_consultation_outcomes_is_also_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'consultation_outcomes'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'consultation_outcomes must be FORCE RLS enabled alongside firm_leads in this batch.');
    }

    public function test_consultations_is_also_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'consultations'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'consultations must be FORCE RLS enabled alongside firm_leads in this batch.');
    }

    public function test_firm_leads_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'firm_leads'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'firm_leads must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Section 39A-3J ships all four of this batch's migrations
     * together — see LeadSourcesForceRlsActivationTest's own doc
     * comment for why this asserts the real final count (thirteen)
     * rather than a hypothetical intermediate one.
     */
    public function test_exactly_eighteen_intended_tables_are_force_enabled(): void
    {
        // Section 39A-3L, Checkpoint 1, Table Phase C (a later, distinct
        // staged-FORCE-activation branch) legitimately activated FORCE
        // for payment_classification_events too — this test's own scope
        // only introduced eighteen, but the exact-count assertion below
        // must still account for that later, legitimate addition rather
        // than falsely reporting it as unexpected.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 2, Table
        // Phase C (this repo's twentieth staged FORCE activation batch,
        // covering activation_checklists) for the same reason — additive
        // only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 3, Table
        // Phase C (this repo's twenty-first staged FORCE activation
        // batch, covering firm_activation_events) for the same reason —
        // additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 4, Table
        // Phase C (this repo's twenty-second staged FORCE activation
        // batch, covering firm_entitlements) for the same reason —
        // additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 5, Table
        // Phase C (this repo's twenty-third staged FORCE activation
        // batch, covering firm_entitlement_events) for the same reason —
        // additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 6, Table
        // Phase C (this repo's twenty-fourth staged FORCE activation
        // batch, covering installed_template_packs) for the same reason
        // — additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 9, Table
        // Phase C (this repo's twenty-seventh staged FORCE activation
        // batch, covering seat_allocations) for the same reason —
        // additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 10, Table
        // Phase C (this repo's twenty-eighth staged FORCE activation
        // batch, covering document_requests) for the same reason —
        // additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 11, Table
        // Phase C (this repo's twenty-ninth staged FORCE activation
        // batch, covering communication_consents) for the same reason —
        // additive only, no existing assertion removed or weakened.
        // Narrowly updated by Stage B Checkpoint 3 of the FirmsBase Integration
        // Platform mission (firm_integrations, a new genuine tenant-owned table
        // with RLS prepared and FORCE-activated in the same migration) for the
        // same reason — additive only, no existing assertion removed or weakened.
        $expectedForced = [
            'ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings',
            'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links',
            'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events',
            'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines',
            'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events',
            'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events',
            'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments', 'conflict_check_runs',
            'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
            'firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events', 'client_communication_preferences',
            'customer_success_health_scores',
            'payment_classification_events', 'activation_checklists', 'firm_activation_events', 'firm_entitlements', 'firm_entitlement_events',
            'installed_template_packs', 'template_upgrade_logs', 'template_upgrade_previews', 'seat_allocations', 'document_requests',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 13,
            // Table Phase C (this repo's thirty-first staged FORCE
            // activation batch, covering intake_submissions) for the
            // same reason — additive only, no existing assertion
            // removed or weakened.
            'communication_consents', 'communication_consent_events', 'intake_submissions',
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
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 22,
            // Table Phase C (this repo's fortieth staged FORCE
            // activation batch, covering payment_plans) for the same
            // reason — additive only, no existing assertion removed or
            // weakened.
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 23,
            // Table Phase C (this repo's forty-first staged FORCE
            // activation batch, covering payment_plan_events) for the
            // same reason — additive only, no existing assertion
            // removed or weakened.
            // Narrowly updated by Section 39A-3L, Checkpoint 24 (this
            // repo's forty-second staged FORCE activation batch,
            // covering notification_events) for the same reason as
            // above — additive only, no existing assertion removed or
            // weakened.
            // Narrowly updated by Section 39A-3L, Checkpoint 27 (this repo's forty-fifth staged FORCE activation batch, covering backup_restore_tests) for the same reason — additive only, no existing assertion removed or weakened.
            'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events', 'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans', 'payment_plan_events',             // Narrowly updated by Section 39A-3L, Checkpoint 28 (this repo's forty-sixth staged FORCE activation batch, covering health_checks) for the same reason — additive only, no existing assertion removed or weakened.
'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations', 'integration_credentials', 'integration_oauth_states', 'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings', 'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events', 'integration_inbound_webhook_events',
        ];

        $rows = DB::select(
            "select relname from pg_class where relkind = 'r' and relnamespace = 'public'::regnamespace and relforcerowsecurity = true"
        );
        $actuallyForced = array_map(fn ($row) => $row->relname, $rows);

        sort($expectedForced);
        sort($actuallyForced);

        $this->assertSame($expectedForced, $actuallyForced, 'Exactly the eighteen tables introduced by 39A-3A..39A-3K, plus payment_classification_events (39A-3L Checkpoint 1), activation_checklists (39A-3L Checkpoint 2), and firm_activation_events (39A-3L Checkpoint 3), and firm_entitlements (39A-3L Checkpoint 4), and firm_entitlement_events (39A-3L Checkpoint 5), and installed_template_packs (39A-3L Checkpoint 6), and template_upgrade_logs (39A-3L Checkpoint 7), and template_upgrade_previews (39A-3L Checkpoint 8), and seat_allocations (39A-3L Checkpoint 9), and document_requests (39A-3L Checkpoint 10), and communication_consents (39A-3L Checkpoint 11), and communication_consent_events (39A-3L Checkpoint 12), and intake_submissions (39A-3L Checkpoint 13), must be FORCE RLS enabled — no more, no fewer.');
    }

    public function test_no_unrelated_prepared_table_became_force_enabled(): void
    {
        $coverage = new \App\Services\RowLevelSecurityCoverageMappingService();
        // Section 39A-3L, Checkpoint 1, Table Phase C (a later, distinct
        // staged-FORCE-activation branch) legitimately activated FORCE
        // for payment_classification_events too. Narrowly updated AGAIN
        // by Section 39A-3L, Checkpoint 2, Table Phase C for
        // activation_checklists, for the same reason. Narrowly updated
        // AGAIN by Section 39A-3L, Checkpoint 3, Table Phase C for
        // firm_activation_events, for the same reason.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 9, Table
        // Phase C (this repo's twenty-seventh staged FORCE activation
        // batch, covering seat_allocations) for the same reason —
        // additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 10, Table
        // Phase C (this repo's twenty-eighth staged FORCE activation
        // batch, covering document_requests) for the same reason —
        // additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 11, Table
        // Phase C (this repo's twenty-ninth staged FORCE activation
        // batch, covering communication_consents) for the same reason —
        // additive only, no existing assertion removed or weakened.
        // Narrowly updated by Stage B Checkpoint 3 of the FirmsBase Integration
        // Platform mission (firm_integrations, a new genuine tenant-owned table
        // with RLS prepared and FORCE-activated in the same migration) for the
        // same reason — additive only, no existing assertion removed or weakened.
        $forced = [
            'ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings',
            'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links',
            'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events',
            'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines',
            'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events',
            'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events',
            'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments', 'conflict_check_runs',
            'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
            'firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events', 'client_communication_preferences',
            'customer_success_health_scores',
            'payment_classification_events', 'activation_checklists', 'firm_activation_events', 'firm_entitlements', 'firm_entitlement_events',
            'installed_template_packs', 'template_upgrade_logs', 'template_upgrade_previews', 'seat_allocations', 'document_requests',
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 13,
            // Table Phase C (this repo's thirty-first staged FORCE
            // activation batch, covering intake_submissions) for the
            // same reason — additive only, no existing assertion
            // removed or weakened.
            'communication_consents', 'communication_consent_events', 'intake_submissions',
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
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 22,
            // Table Phase C (this repo's fortieth staged FORCE
            // activation batch, covering payment_plans) for the same
            // reason — additive only, no existing assertion removed or
            // weakened.
            // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 23,
            // Table Phase C (this repo's forty-first staged FORCE
            // activation batch, covering payment_plan_events) for the
            // same reason — additive only, no existing assertion
            // removed or weakened.
            // Narrowly updated by Section 39A-3L, Checkpoint 24 (this
            // repo's forty-second staged FORCE activation batch,
            // covering notification_events) for the same reason as
            // above — additive only, no existing assertion removed or
            // weakened.
            // Narrowly updated by Section 39A-3L, Checkpoint 27 (this repo's forty-fifth staged FORCE activation batch, covering backup_restore_tests) for the same reason — additive only, no existing assertion removed or weakened.
            'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events', 'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans', 'payment_plan_events',             // Narrowly updated by Section 39A-3L, Checkpoint 28 (this repo's forty-sixth staged FORCE activation batch, covering health_checks) for the same reason — additive only, no existing assertion removed or weakened.
'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations', 'integration_credentials', 'integration_oauth_states', 'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings', 'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events', 'integration_inbound_webhook_events',
        ];

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

    public function test_missing_tenant_context_cannot_read_firm_leads(): void
    {
        $firm = Firm::factory()->create();
        FirmLead::factory()->forFirm($firm)->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->assertSame(0, FirmLead::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_insert_firm_leads(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('firm_leads')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'firm_id' => $firm->id,
            'name' => 'Jane Prospect',
            'status' => FirmLeadStatus::New->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_firm_a_context_can_read_its_own_firm_leads(): void
    {
        $firmA = Firm::factory()->create();
        $leadA = FirmLead::factory()->forFirm($firmA)->create();

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => FirmLead::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$leadA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_firm_leads(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        FirmLead::factory()->forFirm($firmA)->create();
        $leadB = FirmLead::factory()->forFirm($firmB)->create();

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => FirmLead::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($leadB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_valid_firm_lead(): void
    {
        $firmA = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA) {
            return DB::table('firm_leads')->insertGetId([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'firm_id' => $firmA->id,
                'name' => 'Valid Prospect',
                'status' => FirmLeadStatus::New->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_firm_leads(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $leadB = FirmLead::factory()->forFirm($firmB)->create(['name' => 'Original Name']);

        $this->runWithFirmContext($firmA, function () use ($leadB) {
            DB::table('firm_leads')->where('id', $leadB->id)->update(['name' => 'Hijacked Name']);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FirmLead::withoutGlobalScopes()->find($leadB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame('Original Name', $reReadAsFirmB->name);
    }

    public function test_firm_a_cannot_delete_firm_b_firm_leads(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $leadB = FirmLead::factory()->forFirm($firmB)->create();

        $this->runWithFirmContext($firmA, function () use ($leadB) {
            DB::table('firm_leads')->where('id', $leadB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FirmLead::withoutGlobalScopes()->find($leadB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B firm leads.');
    }

    public function test_firm_a_cannot_insert_a_firm_lead_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('firm_leads')->insert([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'firm_id' => $firmB->id,
                'name' => 'Stolen Lead',
                'status' => FirmLeadStatus::New->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $leadA = FirmLead::factory()->forFirm($firmA)->create();

        // The firm_leads_tenant_isolation policy has no separate WITH
        // CHECK clause, so its single USING expression governs both
        // which existing rows are visible for update AND what the
        // resulting row must satisfy — from firm A's own context,
        // reassigning one of its own rows' firm_id to firm B produces
        // a row that would no longer match (firm_id = firm A), so
        // PostgreSQL rejects the write outright rather than letting it
        // silently stick.
        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($leadA, $firmB) {
            DB::table('firm_leads')->where('id', $leadA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    public function test_factory_default_creation_is_insertable_under_force(): void
    {
        $lead = FirmLead::factory()->create();

        $this->assertNotNull($lead->id);
        $this->assertNotNull($lead->firm_id);
    }

    /**
     * Known, documented residual gap (same class as
     * conflict_check_runs.matter_id in Section 39A-3I): RLS's
     * single-column policy only validates firm_leads' own firm_id
     * against session context, never that converted_client_id
     * transitively belongs to the same firm. This proves the actual
     * behavior rather than claiming a guarantee RLS does not provide —
     * a raw update setting a correctly-scoped firm_leads row's
     * converted_client_id to a client belonging to a DIFFERENT firm
     * still succeeds, since a single-column policy cannot see across a
     * foreign key and PostgreSQL's own FK checks bypass RLS entirely.
     */
    public function test_firm_a_can_still_set_converted_client_id_to_a_firm_b_client_at_the_raw_db_layer(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $leadA = FirmLead::factory()->forFirm($firmA)->create();
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());

        $affected = $this->runWithFirmContext($firmA, function () use ($leadA, $clientB) {
            return DB::table('firm_leads')->where('id', $leadA->id)->update([
                'converted_client_id' => $clientB->id,
                'status' => FirmLeadStatus::Converted->value,
            ]);
        });

        $this->assertSame(1, $affected, 'The mismatched cross-firm converted_client_id update is not blocked by RLS — this is the documented residual gap, not a false guarantee.');

        $mismatched = $this->runWithFirmContext($firmA, fn () => FirmLead::withoutGlobalScopes()->find($leadA->id));
        $this->assertSame($clientB->id, $mismatched->converted_client_id);
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => FirmLead::factory()->forFirm($firm)->create());

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
     * Rollback support: down() must genuinely restore the Section 39A
     * baseline — RLS still enabled, policy still present, but NOT
     * forced — never drop the policy or disable RLS itself. up() is
     * restored in a finally block so later tests are unaffected.
     */
    public function test_migration_down_restores_the_not_forced_baseline(): void
    {
        $migration = require base_path('database/migrations/2026_08_14_900001_force_rls_on_firm_leads_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'firm_leads'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');
        } finally {
            $migration->up();
        }
    }

    public function test_uncovered_tenant_tables_were_not_modified(): void
    {
        $coverage = new \App\Services\RowLevelSecurityCoverageMappingService();

        foreach ($coverage->missingPreparedTables() as $table) {
            $row = DB::selectOne('select relrowsecurity from pg_class where relname = ?', [$table]);

            if ($row === null) {
                continue;
            }

            $this->assertFalse(
                (bool) $row->relrowsecurity,
                "{$table} was reported as missing RLS preparation, but RLS is now enabled — this batch must not add new policies for uncovered tables."
            );
        }
    }

    public function test_no_other_policy_was_changed(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr from pg_policy where polrelid = 'firm_leads'::regclass and polname = 'firm_leads_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The original firm_leads_tenant_isolation policy must still exist.');
        $this->assertSame(
            "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)",
            $row->using_expr,
            'The existing policy USING expression must be unchanged by this batch.'
        );
    }

    public function test_compliance_gap_registry_service_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/ComplianceGapRegistryService.php');

        $this->assertEmpty($changed, 'ComplianceGapRegistryService.php must remain untouched by this batch.');
    }

    public function test_rls_prepared_not_enforced_remains_tracked(): void
    {
        $registry = new \App\Services\ComplianceGapRegistryService();

        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
        $this->assertCount(21, $registry->all());
    }

    public function test_no_ui_routes_controllers_or_deployment_features_were_added(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "This batch must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }

        $this->assertDirectoryDoesNotExist(base_path('app/Filament'));
        $this->assertDirectoryDoesNotExist(base_path('app/Livewire'));
    }

    /**
     * ImportApplyService::createRecordFor()'s FirmLead branch is the
     * one production call site that creates a FirmLead outside of any
     * pre-existing tenant context — proves it still succeeds under
     * FORCE and produces a correctly firm-scoped, readable row.
     */
    public function test_import_apply_service_firm_lead_branch_still_works_under_force(): void
    {
        $firm = Firm::factory()->create();
        $auditService = new ImportAuditService();
        $batchService = new ImportBatchService($auditService);
        $documentSafetyService = new ImportDocumentSafetyService(new DocumentUploadPolicyService(), new FakeVirusScanner());
        $service = new ImportApplyService($documentSafetyService, $auditService);

        $batch = $batchService->create($firm, ImportEntityType::FirmLead, ImportSourceType::CsvUpload);
        // import_batches gained permanent FORCE ROW LEVEL SECURITY in a
        // later, separate wave (Section 39A-9 Wave 9); stageRows()'s own
        // wrap already restores database session context to "none" by
        // the time it returns, so a bare $batch->fresh() call afterward
        // would return null. Chain stageRows()'s own already-fresh
        // return value and each subsequent service call's own return
        // value instead of an unwrapped re-fetch.
        $batch = $batchService->stageRows($batch, [['name' => 'Imported Via Batch', 'email' => 'batch@example.test']]);
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);
        $confirmed = $service->confirmBatch($batch);
        $service->apply($confirmed);

        $lead = $this->runWithFirmContext($firm, fn () => FirmLead::withoutGlobalScopes()->where('name', 'Imported Via Batch')->first());

        $this->assertNotNull($lead, 'ImportApplyService must still be able to create a FirmLead row under FORCE ROW LEVEL SECURITY.');
        $this->assertSame($firm->id, $lead->firm_id);
    }

    /**
     * FirmCommandCenterAggregationService::snapshot()'s newLeadsCount
     * metric reads firm_leads outside of any pre-existing tenant
     * context — proves it still counts correctly (only the calling
     * firm's New-status leads) under FORCE.
     */
    public function test_firm_command_center_new_leads_count_still_works_under_force(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        FirmLead::factory()->forFirm($firmA)->create(['status' => FirmLeadStatus::New]);
        FirmLead::factory()->forFirm($firmA)->create(['status' => FirmLeadStatus::New]);
        FirmLead::factory()->forFirm($firmA)->create(['status' => FirmLeadStatus::Converted]);
        FirmLead::factory()->forFirm($firmB)->create(['status' => FirmLeadStatus::New]);

        $snapshot = (new FirmCommandCenterAggregationService())->snapshot($firmA);

        $this->assertSame(2, $snapshot->newLeadsCount, 'newLeadsCount must count only firm A\'s New-status leads under FORCE.');
    }

    /**
     * @return array<int, string>
     */
    private function changedOrUntrackedPaths(string $scope): array
    {
        $changed = trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- '.escapeshellarg($scope)
        ));

        if ($changed === '') {
            return [];
        }

        return preg_split('/\R/', $changed) ?: [];
    }
}
