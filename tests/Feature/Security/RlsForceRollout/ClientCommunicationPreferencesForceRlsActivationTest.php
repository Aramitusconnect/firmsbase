<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\CalendarEvent;
use App\Models\Client;
use App\Models\ClientCommunicationPreference;
use App\Models\ConflictCheckRun;
use App\Models\Consultation;
use App\Models\ConsultationOutcome;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\DocumentChaseRule;
use App\Models\EmployeeRate;
use App\Models\Firm;
use App\Models\FirmLead;
use App\Models\FirmPracticeArea;
use App\Models\FirmUser;
use App\Models\Invoice;
use App\Models\LeadSource;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\Task;
use App\Services\ComplianceGapRegistryService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ClientCommunicationPreferencesForceRlsActivationTest — Section
 * 39A-3K (batch 5 of 5, final table of this batch). Proves the
 * eighteenth staged FORCE ROW LEVEL SECURITY activation batch
 * (database/migrations/2026_08_20_920005_force_rls_on_client_communication_preferences_table.php)
 * is permanently active for client_communication_preferences and
 * behaves correctly: fail-closed with no context, correct cross-firm
 * isolation, correct same-firm access, and that every table forced by
 * a prior section or by this same batch (clients, firm_users,
 * documents, deadlines, tasks, matters, invoices, payments,
 * conflict_check_runs, lead_sources, consultation_outcomes, firm_leads,
 * consultations, firm_practice_areas, document_chase_rules,
 * employee_rates, calendar_events) remains forced simultaneously —
 * this file also carries the combined "all eighteen tables, isolated
 * simultaneously" proof (mirroring Section 39A-3J's own equivalent for
 * its thirteen tables) since it is the last table activated in this
 * batch.
 *
 * No service was changed for this table — the two production read call
 * sites found in prior audits (NotificationEligibilityService::check(),
 * PaymentPlanDunningService::checkAndLog()) were traced and confirmed
 * genuinely unreachable in production today (see the migration's own
 * docblock).
 */
class ClientCommunicationPreferencesForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_thirteen_previously_forced_tables_remain_force_row_level_security_enabled(): void
    {
        $previouslyForced = [
            'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments',
            'conflict_check_runs', 'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
        ];

        foreach ($previouslyForced as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must remain FORCE RLS enabled after this batch.");
        }
    }

    public function test_this_batchs_other_four_tables_are_also_force_row_level_security_enabled(): void
    {
        foreach (['firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events'] as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row);
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must be FORCE RLS enabled alongside client_communication_preferences in this batch.");
        }
    }

    public function test_client_communication_preferences_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'client_communication_preferences'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_client_communication_preferences_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'client_communication_preferences'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'client_communication_preferences must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_exactly_eighteen_intended_tables_are_force_enabled(): void
    {
        // Section 39A-3L, Checkpoint 1, Table Phase C (a later, distinct
        // staged-FORCE-activation branch) legitimately activated FORCE
        // for payment_classification_events too — this test's own scope
        // (39A-3K) only introduced eighteen, but the exact-count
        // assertion below must still account for that later, legitimate
        // addition rather than falsely reporting it as unexpected.
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
            'customer_success_health_scores',
            'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
            'firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events', 'client_communication_preferences',
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
        $coverage = new RowLevelSecurityCoverageMappingService();
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
            'customer_success_health_scores',
            'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
            'firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events', 'client_communication_preferences',
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

    public function test_missing_tenant_context_cannot_read_client_communication_preferences(): void
    {
        $firm = Firm::factory()->create();
        ClientCommunicationPreference::factory()->forFirm($firm)->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->assertSame(0, ClientCommunicationPreference::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_write_client_communication_preferences(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('client_communication_preferences')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'firm_id' => $firm->id,
            'preferred_language' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_firm_a_context_can_read_its_own_client_communication_preferences(): void
    {
        $firmA = Firm::factory()->create();
        $prefA = $this->runWithFirmContext($firmA, fn () => ClientCommunicationPreference::factory()->forFirm($firmA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => ClientCommunicationPreference::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$prefA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_client_communication_preferences(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->runWithFirmContext($firmA, fn () => ClientCommunicationPreference::factory()->forFirm($firmA)->create());
        $prefB = $this->runWithFirmContext($firmB, fn () => ClientCommunicationPreference::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => ClientCommunicationPreference::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($prefB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_valid_client_communication_preference(): void
    {
        $firmA = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA) {
            return DB::table('client_communication_preferences')->insertGetId([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'firm_id' => $firmA->id,
                'preferred_language' => 'en',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_client_communication_preferences(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $prefB = $this->runWithFirmContext($firmB, fn () => ClientCommunicationPreference::factory()->forFirm($firmB)->create(['preferred_language' => 'en']));

        $this->runWithFirmContext($firmA, function () use ($prefB) {
            DB::table('client_communication_preferences')->where('id', $prefB->id)->update(['preferred_language' => 'fr']);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => ClientCommunicationPreference::withoutGlobalScopes()->find($prefB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame('en', $reReadAsFirmB->preferred_language);
    }

    public function test_firm_a_cannot_delete_firm_b_client_communication_preferences(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $prefB = $this->runWithFirmContext($firmB, fn () => ClientCommunicationPreference::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($prefB) {
            DB::table('client_communication_preferences')->where('id', $prefB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => ClientCommunicationPreference::withoutGlobalScopes()->find($prefB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B client_communication_preferences.');
    }

    public function test_firm_a_cannot_insert_a_client_communication_preference_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('client_communication_preferences')->insert([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'firm_id' => $firmB->id,
                'preferred_language' => 'en',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $prefA = $this->runWithFirmContext($firmA, fn () => ClientCommunicationPreference::factory()->forFirm($firmA)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($prefA, $firmB) {
            DB::table('client_communication_preferences')->where('id', $prefA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => ClientCommunicationPreference::factory()->forFirm($firm)->create());

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
     * The context-hold create() pattern: a bare
     * ClientCommunicationPreference::factory()->create() (no explicit
     * firm/client — client_id defaults to null, a genuinely nullable
     * column) must still succeed and be immediately readable.
     */
    public function test_default_factory_creation_is_safe_and_immediately_readable(): void
    {
        $pref = ClientCommunicationPreference::factory()->create();

        $this->assertNotNull($pref->id);
        $this->assertNotNull($pref->firm_id);
        $this->assertNull($pref->client_id);

        $reReadUnderOwnFirm = $this->runWithFirmContext(
            $pref->firm_id,
            fn () => ClientCommunicationPreference::withoutGlobalScopes()->find($pref->id),
        );

        $this->assertNotNull($reReadUnderOwnFirm, 'A bare ClientCommunicationPreference::factory()->create() must be readable under its own firm context.');
    }

    /**
     * Table-specific required proof (per the batch report): creates a
     * client + preference via forClient(), independently RE-QUERIES the
     * client afterward (a fresh DB read via Client::withoutGlobalScopes()
     * ->find(), not the in-memory $client object that was passed in),
     * and asserts the client's own firm_id matches the preference row's
     * firm_id — proving forClient() genuinely derives firm_id from the
     * client's real, persisted row rather than merely trusting an
     * in-memory attribute that could have gone stale.
     */
    public function test_for_client_state_produces_a_preference_whose_firm_id_matches_the_clients_own_persisted_firm_id(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        $preference = $this->runWithFirmContext($firm, fn () => ClientCommunicationPreference::factory()->forClient($client)->create());

        $freshClient = $this->runWithFirmContext($firm, fn () => Client::withoutGlobalScopes()->find($client->id));

        $this->assertNotNull($freshClient, 'The client must be independently re-queryable (fresh DB read) after creation.');
        $this->assertSame(
            $freshClient->firm_id,
            $preference->firm_id,
            'forClient() must produce a preference whose firm_id matches the client\'s own PERSISTED firm_id, not a stale/assumed value.'
        );
        $this->assertSame($client->id, $preference->client_id);
    }

    /**
     * Same proof for withClient() — which creates its OWN client
     * internally rather than accepting one — must be independently
     * verified the same way, not assumed to behave like forClient().
     */
    public function test_with_client_state_produces_a_preference_whose_firm_id_matches_its_generated_clients_own_persisted_firm_id(): void
    {
        $preference = ClientCommunicationPreference::factory()->withClient()->create();

        $this->assertNotNull($preference->client_id, 'withClient() must generate and attach a real client.');

        $freshClient = $this->runWithFirmContext(
            $preference->firm_id,
            fn () => Client::withoutGlobalScopes()->find($preference->client_id),
        );

        $this->assertNotNull($freshClient, 'The client generated by withClient() must be independently re-queryable (fresh DB read).');
        $this->assertSame(
            $freshClient->firm_id,
            $preference->firm_id,
            'withClient() must produce a preference whose firm_id matches its generated client\'s own PERSISTED firm_id.'
        );
    }

    /**
     * Rollback support: down() must genuinely restore the Section 39A
     * baseline — RLS still enabled, policy still present, but NOT
     * forced — never drop the policy or disable RLS itself. up() is
     * restored in a finally block so later tests are unaffected.
     */
    public function test_migration_down_restores_the_not_forced_baseline(): void
    {
        $migration = require base_path('database/migrations/2026_08_20_920005_force_rls_on_client_communication_preferences_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'client_communication_preferences'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');
        } finally {
            $migration->up();
        }
    }

    public function test_uncovered_tenant_tables_were_not_modified(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();

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
            "select pg_get_expr(polqual, polrelid) as using_expr from pg_policy where polrelid = 'client_communication_preferences'::regclass and polname = 'client_communication_preferences_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The original client_communication_preferences_tenant_isolation policy must still exist.');
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
        $registry = new ComplianceGapRegistryService();

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
     * All eighteen forced tables must be isolated independently and
     * simultaneously — proof this batch did not weaken or interfere
     * with any of the prior thirteen tables' own enforcement, nor with
     * its own four siblings.
     */
    public function test_all_eighteen_forced_tables_are_isolated_independently_and_simultaneously(): void
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
        $joinA = $this->runWithFirmContext($firmA, fn () => FirmPracticeArea::factory()->forFirm($firmA)->create());
        $joinB = $this->runWithFirmContext($firmB, fn () => FirmPracticeArea::factory()->forFirm($firmB)->create());
        $ruleA = $this->runWithFirmContext($firmA, fn () => DocumentChaseRule::factory()->forFirm($firmA)->create());
        $ruleB = $this->runWithFirmContext($firmB, fn () => DocumentChaseRule::factory()->forFirm($firmB)->create());
        $rateA = $this->runWithFirmContext($firmA, fn () => EmployeeRate::factory()->forFirm($firmA)->create());
        $rateB = $this->runWithFirmContext($firmB, fn () => EmployeeRate::factory()->forFirm($firmB)->create());
        $eventA = $this->runWithFirmContext($firmA, fn () => CalendarEvent::factory()->create(['firm_id' => $firmA->id]));
        $eventB = $this->runWithFirmContext($firmB, fn () => CalendarEvent::factory()->create(['firm_id' => $firmB->id]));
        $prefA = $this->runWithFirmContext($firmA, fn () => ClientCommunicationPreference::factory()->forFirm($firmA)->create());
        $prefB = $this->runWithFirmContext($firmB, fn () => ClientCommunicationPreference::factory()->forFirm($firmB)->create());

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
            'firm_practice_areas' => FirmPracticeArea::withoutGlobalScopes()->pluck('id')->all(),
            'document_chase_rules' => DocumentChaseRule::withoutGlobalScopes()->pluck('id')->all(),
            'employee_rates' => EmployeeRate::withoutGlobalScopes()->pluck('id')->all(),
            'calendar_events' => CalendarEvent::withoutGlobalScopes()->pluck('id')->all(),
            'client_communication_preferences' => ClientCommunicationPreference::withoutGlobalScopes()->pluck('id')->all(),
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
        $this->assertSame([$joinA->id], $resultA['firm_practice_areas']);
        $this->assertNotContains($joinB->id, $resultA['firm_practice_areas']);
        $this->assertSame([$ruleA->id], $resultA['document_chase_rules']);
        $this->assertNotContains($ruleB->id, $resultA['document_chase_rules']);
        $this->assertSame([$rateA->id], $resultA['employee_rates']);
        $this->assertNotContains($rateB->id, $resultA['employee_rates']);
        $this->assertSame([$eventA->id], $resultA['calendar_events']);
        $this->assertNotContains($eventB->id, $resultA['calendar_events']);
        $this->assertSame([$prefA->id], $resultA['client_communication_preferences']);
        $this->assertNotContains($prefB->id, $resultA['client_communication_preferences']);
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
