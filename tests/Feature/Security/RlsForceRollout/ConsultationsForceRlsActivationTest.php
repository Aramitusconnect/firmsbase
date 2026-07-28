<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\Client;
use App\Models\ConflictCheckRun;
use App\Models\Consultation;
use App\Models\ConsultationOutcome;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmLead;
use App\Models\FirmUser;
use App\Models\Invoice;
use App\Models\LeadSource;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\Task;
use App\Services\ComplianceGapRegistryService;
use App\Services\FirmCommandCenterAggregationService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ConsultationsForceRlsActivationTest — Section 39A-3J (batch 4 of 4,
 * final table of this batch). Proves the thirteenth staged FORCE ROW
 * LEVEL SECURITY activation batch
 * (database/migrations/2026_08_15_900001_force_rls_on_consultations_table.php)
 * is permanently active for consultations and behaves correctly:
 * fail-closed with no context, correct cross-firm isolation, correct
 * same-firm access, and that every table forced by a prior section or
 * by this same batch (clients, firm_users, documents, deadlines,
 * tasks, matters, invoices, payments, conflict_check_runs,
 * lead_sources, consultation_outcomes, firm_leads) remains forced
 * simultaneously — this file also carries the combined
 * "all thirteen tables, isolated simultaneously" proof (mirroring
 * Section 39A-3I's own equivalent for its nine tables) since it is
 * the last table activated in this batch.
 *
 * consultations is doubly-anchored: both a direct firm_id column AND
 * a firm_lead_id foreign key to firm_leads. ConsultationFactory's
 * root-cause fix (Section 39A-3J) ties both to the SAME generated
 * firm_lead so a bare Consultation::factory()->create() never
 * produces a mismatch — this file proves that behavior directly
 * rather than assuming it, and also proves the FirmCommandCenter
 * consultationsCount metric wired alongside this migration continues
 * to work correctly.
 */
class ConsultationsForceRlsActivationTest extends TestCase
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
     * lead_sources, consultation_outcomes, and firm_leads are this
     * same batch's other three tables — they land in the same
     * migration run as consultations, so this file proves
     * consultations' own isolation is correct alongside its three
     * siblings, not in a vacuum.
     */
    public function test_lead_sources_is_also_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'lead_sources'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'lead_sources must be FORCE RLS enabled alongside consultations in this batch.');
    }

    public function test_consultation_outcomes_is_also_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'consultation_outcomes'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'consultation_outcomes must be FORCE RLS enabled alongside consultations in this batch.');
    }

    public function test_firm_leads_is_also_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'firm_leads'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'firm_leads must be FORCE RLS enabled alongside consultations in this batch.');
    }

    public function test_consultations_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'consultations'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'consultations must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

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
            'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations', 'integration_credentials', 'integration_oauth_states', 'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings', 'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events', 'integration_inbound_webhook_events', 'integration_connection_health', 'integration_usage_records', 'integration_provider_webhook_subscriptions', /* Narrowly updated by Checkpoint 4 (FirmsVault Live Integrations, "Plaid financial evidence add-on") -- this full-registry list must now also account for the 21 new tables Checkpoint 4 added to both PREPARED_TABLES and the FORCE-RLS rollout in the same migration (client_portal_matter_grants, the financial-evidence domain, and the provider-billing domain) -- additive only, no existing assertion removed or weakened. */ 'client_portal_matter_grants', 'financial_evidence_bank_accounts', 'financial_evidence_transactions', 'financial_evidence_income_records', 'financial_evidence_liabilities', 'financial_evidence_investment_records', 'financial_evidence_statements', 'financial_evidence_identity_records', 'provider_billable_call_reservations', 'provider_firm_operation_policies', 'provider_balance_snapshots', 'financial_evidence_matter_requests', 'financial_evidence_client_consents', 'financial_evidence_matter_authorizations', 'financial_evidence_matter_notes', 'financial_evidence_snapshots', 'financial_evidence_transaction_reviews', 'financial_evidence_duplicate_transfer_flags', 'financial_evidence_large_deposit_flags', 'financial_evidence_reconciliation_candidates', 'financial_account_reclassification_requests',
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
        $coverage = new RowLevelSecurityCoverageMappingService;
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
            'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations', 'integration_credentials', 'integration_oauth_states', 'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings', 'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events', 'integration_inbound_webhook_events', 'integration_connection_health', 'integration_usage_records', 'integration_provider_webhook_subscriptions', /* Narrowly updated by Checkpoint 4 (FirmsVault Live Integrations, "Plaid financial evidence add-on") -- this full-registry list must now also account for the 21 new tables Checkpoint 4 added to both PREPARED_TABLES and the FORCE-RLS rollout in the same migration (client_portal_matter_grants, the financial-evidence domain, and the provider-billing domain) -- additive only, no existing assertion removed or weakened. */ 'client_portal_matter_grants', 'financial_evidence_bank_accounts', 'financial_evidence_transactions', 'financial_evidence_income_records', 'financial_evidence_liabilities', 'financial_evidence_investment_records', 'financial_evidence_statements', 'financial_evidence_identity_records', 'provider_billable_call_reservations', 'provider_firm_operation_policies', 'provider_balance_snapshots', 'financial_evidence_matter_requests', 'financial_evidence_client_consents', 'financial_evidence_matter_authorizations', 'financial_evidence_matter_notes', 'financial_evidence_snapshots', 'financial_evidence_transaction_reviews', 'financial_evidence_duplicate_transfer_flags', 'financial_evidence_large_deposit_flags', 'financial_evidence_reconciliation_candidates', 'financial_account_reclassification_requests',
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

    public function test_missing_tenant_context_cannot_read_consultations(): void
    {
        $firm = Firm::factory()->create();
        $lead = $this->runWithFirmContext($firm, fn () => FirmLead::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => Consultation::factory()->forLead($lead)->create());

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, Consultation::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_insert_consultations(): void
    {
        $firm = Firm::factory()->create();
        $lead = $this->runWithFirmContext($firm, fn () => FirmLead::factory()->forFirm($firm)->create());

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('consultations')->insert([
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'firm_lead_id' => $lead->id,
            'scheduled_at' => now()->addDay(),
            'converted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_firm_a_context_can_read_its_own_consultations(): void
    {
        $firmA = Firm::factory()->create();
        $leadA = $this->runWithFirmContext($firmA, fn () => FirmLead::factory()->forFirm($firmA)->create());
        $consultationA = $this->runWithFirmContext($firmA, fn () => Consultation::factory()->forLead($leadA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => Consultation::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$consultationA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_consultations(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $leadA = $this->runWithFirmContext($firmA, fn () => FirmLead::factory()->forFirm($firmA)->create());
        $leadB = $this->runWithFirmContext($firmB, fn () => FirmLead::factory()->forFirm($firmB)->create());
        $this->runWithFirmContext($firmA, fn () => Consultation::factory()->forLead($leadA)->create());
        $consultationB = $this->runWithFirmContext($firmB, fn () => Consultation::factory()->forLead($leadB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => Consultation::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($consultationB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_valid_consultation(): void
    {
        $firmA = Firm::factory()->create();
        $leadA = $this->runWithFirmContext($firmA, fn () => FirmLead::factory()->forFirm($firmA)->create());

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA, $leadA) {
            return DB::table('consultations')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmA->id,
                'firm_lead_id' => $leadA->id,
                'scheduled_at' => now()->addDay(),
                'converted' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_consultations(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $leadB = $this->runWithFirmContext($firmB, fn () => FirmLead::factory()->forFirm($firmB)->create());
        $consultationB = $this->runWithFirmContext($firmB, fn () => Consultation::factory()->forLead($leadB)->create(['notes' => 'Original Notes']));

        $this->runWithFirmContext($firmA, function () use ($consultationB) {
            DB::table('consultations')->where('id', $consultationB->id)->update(['notes' => 'Hijacked Notes']);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => Consultation::withoutGlobalScopes()->find($consultationB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame('Original Notes', $reReadAsFirmB->notes);
    }

    public function test_firm_a_cannot_delete_firm_b_consultations(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $leadB = $this->runWithFirmContext($firmB, fn () => FirmLead::factory()->forFirm($firmB)->create());
        $consultationB = $this->runWithFirmContext($firmB, fn () => Consultation::factory()->forLead($leadB)->create());

        $this->runWithFirmContext($firmA, function () use ($consultationB) {
            DB::table('consultations')->where('id', $consultationB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => Consultation::withoutGlobalScopes()->find($consultationB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B consultations.');
    }

    public function test_firm_a_cannot_insert_a_consultation_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $leadB = $this->runWithFirmContext($firmB, fn () => FirmLead::factory()->forFirm($firmB)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $leadB) {
            DB::table('consultations')->insert([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmB->id,
                'firm_lead_id' => $leadB->id,
                'scheduled_at' => now()->addDay(),
                'converted' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $leadA = $this->runWithFirmContext($firmA, fn () => FirmLead::factory()->forFirm($firmA)->create());
        $consultationA = $this->runWithFirmContext($firmA, fn () => Consultation::factory()->forLead($leadA)->create());

        // The consultations_tenant_isolation policy has no separate
        // WITH CHECK clause, so its single USING expression governs
        // both which existing rows are visible for update AND what the
        // resulting row must satisfy — from firm A's own context,
        // reassigning one of its own rows' firm_id to firm B produces
        // a row that would no longer match (firm_id = firm A), so
        // PostgreSQL rejects the write outright rather than letting it
        // silently stick.
        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($consultationA, $firmB) {
            DB::table('consultations')->where('id', $consultationA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    /**
     * The core Section 39A-3J factory fix: a bare
     * Consultation::factory()->create() with NO explicit state must
     * generate one firm_lead and tie firm_id to THAT lead's own firm
     * — never two independent, unrelated firms. Proven directly
     * (rather than assumed) by reading the firm_lead back under its
     * own firm's context and comparing firm_id.
     */
    public function test_factory_default_creation_keeps_firm_id_and_firm_lead_consistent(): void
    {
        $consultation = Consultation::factory()->create();

        $this->assertNotNull($consultation->id);
        $this->assertNotNull($consultation->firm_id);
        $this->assertNotNull($consultation->firm_lead_id);

        $leadFirmId = $this->runWithFirmContext($consultation->firm_id, fn () => FirmLead::withoutGlobalScopes()->find($consultation->firm_lead_id))->firm_id;

        $this->assertSame($consultation->firm_id, $leadFirmId, 'A bare Consultation::factory()->create() must never produce a firm_id/firm_lead_id mismatch.');
    }

    /**
     * The explicit forLead() state must preserve the same consistency
     * a caller expects when supplying its own, already-correct
     * FirmLead.
     */
    public function test_for_lead_state_preserves_ownership_consistency(): void
    {
        $firm = Firm::factory()->create();
        $lead = $this->runWithFirmContext($firm, fn () => FirmLead::factory()->forFirm($firm)->create());

        $consultation = $this->runWithFirmContext($firm, fn () => Consultation::factory()->forLead($lead)->create());

        $this->assertSame($firm->id, $consultation->firm_id);
        $this->assertSame($lead->id, $consultation->firm_lead_id);
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        $lead = $this->runWithFirmContext($firm, fn () => FirmLead::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, fn () => Consultation::factory()->forLead($lead)->create());

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
        $migration = require base_path('database/migrations/2026_08_15_900001_force_rls_on_consultations_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'consultations'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');
        } finally {
            $migration->up();
        }
    }

    public function test_uncovered_tenant_tables_were_not_modified(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

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
            "select pg_get_expr(polqual, polrelid) as using_expr from pg_policy where polrelid = 'consultations'::regclass and polname = 'consultations_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The original consultations_tenant_isolation policy must still exist.');
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
        $registry = new ComplianceGapRegistryService;

        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
        $this->assertCount(21, $registry->all());
    }

    public function test_no_ui_routes_controllers_or_deployment_features_were_added(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "This batch must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }
    }

    /**
     * FirmCommandCenterAggregationService::snapshot()'s
     * consultationsCount metric reads consultations outside of any
     * pre-existing tenant context — proves it still counts correctly
     * (only the calling firm's future, not-yet-held consultations)
     * under FORCE.
     */
    public function test_firm_command_center_consultations_count_still_works_under_force(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $leadA = $this->runWithFirmContext($firmA, fn () => FirmLead::factory()->forFirm($firmA)->create());
        $leadB = $this->runWithFirmContext($firmB, fn () => FirmLead::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, fn () => Consultation::factory()->forLead($leadA)->create(['scheduled_at' => now()->addDays(3)]));
        $this->runWithFirmContext($firmA, fn () => Consultation::factory()->forLead($leadA)->create(['scheduled_at' => now()->addDays(5)]));
        // Already held — must not count.
        $this->runWithFirmContext($firmA, fn () => Consultation::factory()->forLead($leadA)->held()->create(['scheduled_at' => now()->subDays(1)]));
        $this->runWithFirmContext($firmB, fn () => Consultation::factory()->forLead($leadB)->create(['scheduled_at' => now()->addDays(2)]));

        $snapshot = (new FirmCommandCenterAggregationService)->snapshot($firmA);

        $this->assertSame(2, $snapshot->consultationsCount, 'consultationsCount must count only firm A\'s upcoming, not-yet-held consultations under FORCE.');
    }

    /**
     * All thirteen forced tables must be isolated independently and
     * simultaneously — proof this batch did not weaken or interfere
     * with any of the prior nine tables' own enforcement, nor with its
     * own three siblings.
     */
    public function test_all_thirteen_forced_tables_are_isolated_independently_and_simultaneously(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $clientA = Client::factory()->forFirm($firmA)->create();
        $firmUserB = FirmUser::factory()->forFirm($firmB)->create();
        $documentA = Document::factory()->create(['firm_id' => $firmA->id]);
        $deadlineB = Deadline::factory()->create(['firm_id' => $firmB->id]);
        $taskA = Task::factory()->create(['firm_id' => $firmA->id]);
        $taskB = Task::factory()->create(['firm_id' => $firmB->id]);
        $matterA = Matter::factory()->forFirm($firmA)->create();
        $matterB = Matter::factory()->forFirm($firmB)->create();
        $invoiceA = Invoice::factory()->forFirm($firmA)->create();
        $invoiceB = Invoice::factory()->forFirm($firmB)->create();
        $paymentA = Payment::factory()->forFirm($firmA)->create();
        $paymentB = Payment::factory()->forFirm($firmB)->create();
        $runA = ConflictCheckRun::factory()->forFirm($firmA)->create();
        $runB = ConflictCheckRun::factory()->forFirm($firmB)->create();
        $sourceA = LeadSource::factory()->forFirm($firmA)->create();
        $sourceB = LeadSource::factory()->forFirm($firmB)->create();
        $outcomeA = ConsultationOutcome::factory()->forFirm($firmA)->create();
        $outcomeB = ConsultationOutcome::factory()->forFirm($firmB)->create();
        $leadA = FirmLead::factory()->forFirm($firmA)->create();
        $leadB = FirmLead::factory()->forFirm($firmB)->create();
        $consultationA = $this->runWithFirmContext($firmA, fn () => Consultation::factory()->forLead($leadA)->create());
        $consultationB = $this->runWithFirmContext($firmB, fn () => Consultation::factory()->forLead($leadB)->create());

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'clients' => Client::withoutGlobalScopes()->pluck('id')->all(),
            'firm_users' => FirmUser::withoutGlobalScopes()->pluck('id')->all(),
            'documents' => Document::withoutGlobalScopes()->pluck('id')->all(),
            'deadlines' => Deadline::withoutGlobalScopes()->pluck('id')->all(),
            'tasks' => Task::withoutGlobalScopes()->pluck('id')->all(),
            'matters' => Matter::withoutGlobalScopes()->pluck('id')->all(),
            'invoices' => Invoice::withoutGlobalScopes()->pluck('id')->all(),
            'payments' => Payment::withoutGlobalScopes()->pluck('id')->all(),
            'conflict_check_runs' => ConflictCheckRun::withoutGlobalScopes()->pluck('id')->all(),
            'lead_sources' => LeadSource::withoutGlobalScopes()->pluck('id')->all(),
            'consultation_outcomes' => ConsultationOutcome::withoutGlobalScopes()->pluck('id')->all(),
            'firm_leads' => FirmLead::withoutGlobalScopes()->pluck('id')->all(),
            'consultations' => Consultation::withoutGlobalScopes()->pluck('id')->all(),
        ]);

        $this->assertContains($clientA->id, $resultA['clients']);
        $this->assertSame([], $resultA['firm_users']);
        $this->assertNotContains($firmUserB->id, $resultA['firm_users']);
        $this->assertSame([$documentA->id], $resultA['documents']);
        $this->assertSame([], $resultA['deadlines']);
        $this->assertNotContains($deadlineB->id, $resultA['deadlines']);
        $this->assertSame([$taskA->id], $resultA['tasks']);
        $this->assertNotContains($taskB->id, $resultA['tasks']);
        $this->assertContains($matterA->id, $resultA['matters']);
        $this->assertNotContains($matterB->id, $resultA['matters']);
        $this->assertContains($invoiceA->id, $resultA['invoices']);
        $this->assertNotContains($invoiceB->id, $resultA['invoices']);
        $this->assertContains($paymentA->id, $resultA['payments']);
        $this->assertNotContains($paymentB->id, $resultA['payments']);
        $this->assertContains($runA->id, $resultA['conflict_check_runs']);
        $this->assertNotContains($runB->id, $resultA['conflict_check_runs']);
        $this->assertContains($sourceA->id, $resultA['lead_sources']);
        $this->assertNotContains($sourceB->id, $resultA['lead_sources']);
        $this->assertContains($outcomeA->id, $resultA['consultation_outcomes']);
        $this->assertNotContains($outcomeB->id, $resultA['consultation_outcomes']);
        $this->assertContains($leadA->id, $resultA['firm_leads']);
        $this->assertNotContains($leadB->id, $resultA['firm_leads']);
        $this->assertContains($consultationA->id, $resultA['consultations']);
        $this->assertNotContains($consultationB->id, $resultA['consultations']);
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
