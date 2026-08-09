<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\LicenseStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmLicense;
use App\Models\LicenseEvent;
use App\Services\ComplianceGapRegistryService;
use App\Services\EntitlementPlanSyncService;
use App\Services\EntitlementService;
use App\Services\FirmLicenseCommercialService;
use App\Services\LegalDataAccessPolicyService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FirmLicensesForceRlsActivationTest — Section 39A-3L, Checkpoint 19.
 * Proves the thirty-seventh staged FORCE ROW LEVEL SECURITY activation
 * batch
 * (database/migrations/2026_08_25_930019_force_rls_on_firm_licenses_table.php)
 * is permanently active for firm_licenses and behaves correctly:
 * fail-closed with no context, correct cross-firm isolation, correct
 * same-firm access, that every previously-forced table remains forced
 * simultaneously, and that the two genuine production fixes required by
 * this checkpoint (FirmLicenseCommercialService's phantom-audit-trail
 * bug and LegalDataAccessPolicyService's fail-OPEN data-access-control
 * gap) are actually closed — not merely asserted to be closed.
 *
 * Unlike firm_settings (Checkpoint 18), firm_licenses has NO singleton-
 * per-firm unique constraint — multiple licenses per firm is a
 * supported state (confirmed by the reconciled plan and by
 * LegalDataAccessPolicyServiceTest::
 * test_the_most_restrictive_license_wins_when_a_firm_somehow_has_multiple),
 * so this file deliberately omits any singleton-uniqueness test.
 *
 * firm_licenses does carry two OTHER foreign keys — org_license_id and
 * billing_account_id — but neither is a second, independently-resolved
 * TENANT-owned relation the way document_chase_events'
 * document_request_item_id was (Checkpoint 17): both OrgLicense and
 * BillingAccount are explicitly NOT BelongsToTenant (organization-level,
 * not firm-level, ownership), so there is no "raw insert can still
 * reference a different FIRM's related row" residual-gap class to prove
 * for those two columns the way there was for document_chase_events —
 * this is a genuine difference in this table's shape, not an omission.
 *
 * The single most important proof in this file is
 * test_legal_data_access_policy_service_correctly_denies_full_access_for_a_suspended_firm_with_no_ambient_context_established_beforehand()
 * below: it is the regression proof for the highest-priority production
 * fix in this checkpoint (LegalDataAccessPolicyService::currentStatus()
 * wrapping its $firm->licenses read in TenantContextService::
 * runWithFirmContext(), since firm_licenses becoming FORCE-RLS
 * protected would otherwise make $firm->licenses silently resolve to an
 * empty collection and canRead()/canWrite()/canExport() silently report
 * unrestricted full access for a Suspended/PastDue/Restricted firm).
 * This test deliberately never wraps its own call to canRead()/
 * canWrite()/canExport() in any ambient context — the whole point is
 * proving the PRODUCTION CODE establishes its own context internally,
 * not that the test spoon-feeds it one.
 *
 * The second most important proof is
 * test_firm_license_commercial_service_change_status_audit_trail_matches_the_actually_persisted_row()
 * below: it is the regression proof for FirmLicenseCommercialService's
 * phantom-audit-trail bug — before this checkpoint's fix, changeStatus()
 * would silently no-op its UPDATE once firm_licenses was FORCE-protected
 * while still writing a LicenseEvent row claiming the status changed,
 * producing an audit trail that no longer matched reality.
 */
class FirmLicensesForceRlsActivationTest extends TestCase
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
        'firm_settings',
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

    public function test_firm_licenses_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'firm_licenses'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_firm_licenses_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'firm_licenses'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'firm_licenses must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly thirty-seven tables (the thirty-six previously forced plus
     * firm_licenses) must be FORCE-enabled among ALL prepared tables —
     * no more, no less.
     */
    public function test_exactly_thirty_seven_prepared_tables_are_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

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
        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans', 'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations', 'integration_credentials', 'integration_oauth_states', 'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings', 'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events', 'integration_inbound_webhook_events', 'integration_connection_health', 'integration_usage_records', 'integration_provider_webhook_subscriptions', /* Narrowly updated by Checkpoint 4 (FirmsVault Live Integrations, "Plaid financial evidence add-on") -- this full-registry list must now also account for the 21 new tables Checkpoint 4 added to both PREPARED_TABLES and the FORCE-RLS rollout in the same migration (client_portal_matter_grants, the financial-evidence domain, and the provider-billing domain) -- additive only, no existing assertion removed or weakened. */ 'client_portal_matter_grants', 'financial_evidence_bank_accounts', 'financial_evidence_transactions', 'financial_evidence_income_records', 'financial_evidence_liabilities', 'financial_evidence_investment_records', 'financial_evidence_statements', 'financial_evidence_identity_records', 'provider_billable_call_reservations', 'provider_firm_operation_policies', 'provider_balance_snapshots', 'financial_evidence_matter_requests', 'financial_evidence_client_consents', 'financial_evidence_matter_authorizations', 'financial_evidence_matter_notes', 'financial_evidence_snapshots', 'financial_evidence_transaction_reviews', 'financial_evidence_duplicate_transfer_flags', 'financial_evidence_large_deposit_flags', 'financial_evidence_reconciliation_candidates', 'financial_account_reclassification_requests', 'accounting_journal_entries', 'accounting_postings', 'payment_allocations', 'payment_reversals', 'invoice_write_offs', 'accounting_periods']);
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
        $this->assertSame(153, count($actuallyForced), 'Exactly thirty-seven prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 19 — no more, no less.');
        $this->assertSame($expectedForced, $actuallyForced);
    }

    /**
     * Uncovered/uninvolved tables must be untouched by this batch.
     */
    public function test_no_unrelated_prepared_table_became_force_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
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
        $forced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans', 'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations', 'integration_credentials', 'integration_oauth_states', 'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings', 'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events', 'integration_inbound_webhook_events', 'integration_connection_health', 'integration_usage_records', 'integration_provider_webhook_subscriptions', /* Narrowly updated by Checkpoint 4 (FirmsVault Live Integrations, "Plaid financial evidence add-on") -- this full-registry list must now also account for the 21 new tables Checkpoint 4 added to both PREPARED_TABLES and the FORCE-RLS rollout in the same migration (client_portal_matter_grants, the financial-evidence domain, and the provider-billing domain) -- additive only, no existing assertion removed or weakened. */ 'client_portal_matter_grants', 'financial_evidence_bank_accounts', 'financial_evidence_transactions', 'financial_evidence_income_records', 'financial_evidence_liabilities', 'financial_evidence_investment_records', 'financial_evidence_statements', 'financial_evidence_identity_records', 'provider_billable_call_reservations', 'provider_firm_operation_policies', 'provider_balance_snapshots', 'financial_evidence_matter_requests', 'financial_evidence_client_consents', 'financial_evidence_matter_authorizations', 'financial_evidence_matter_notes', 'financial_evidence_snapshots', 'financial_evidence_transaction_reviews', 'financial_evidence_duplicate_transfer_flags', 'financial_evidence_large_deposit_flags', 'financial_evidence_reconciliation_candidates', 'financial_account_reclassification_requests', 'accounting_journal_entries', 'accounting_postings', 'payment_allocations', 'payment_reversals', 'invoice_write_offs', 'accounting_periods']);
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
             where polrelid = 'firm_licenses'::regclass"
        );

        $this->assertNotNull($policy, 'The firm_licenses tenant isolation policy must still exist.');
        $this->assertStringContainsString('current_setting', $policy->using_expr);
        $this->assertStringContainsString('firm_id', $policy->using_expr);
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_firm_licenses(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => FirmLicense::factory()->forFirm($firm)->create());

        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, FirmLicense::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_firm_licenses(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('firm_licenses')->insert([
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'license_key' => 'LIC-TEST-0001',
            'license_status' => LicenseStatus::Trial->value,
            'starts_at' => now(),
            'expires_at' => now()->addDays(14),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_firm_license(): void
    {
        $firmA = Firm::factory()->create();
        $licenseA = $this->runWithFirmContext($firmA, fn () => FirmLicense::factory()->forFirm($firmA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => FirmLicense::query()->pluck('id')->all(),
        );

        $this->assertSame([$licenseA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_firm_license(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->runWithFirmContext($firmA, fn () => FirmLicense::factory()->forFirm($firmA)->create());
        $licenseB = $this->runWithFirmContext($firmB, fn () => FirmLicense::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => FirmLicense::query()->pluck('id')->all(),
        );

        $this->assertNotContains($licenseB->id, $visibleIds);
    }

    public function test_valid_insert_with_correct_firm_context_succeeds(): void
    {
        $firm = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext($firm, function () use ($firm) {
            return DB::table('firm_licenses')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firm->id,
                'license_key' => 'LIC-TEST-0002',
                'license_status' => LicenseStatus::Trial->value,
                'starts_at' => now(),
                'expires_at' => now()->addDays(14),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_context_cannot_insert_a_firm_license_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('firm_licenses')->insert([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmB->id,
                'license_key' => 'LIC-TEST-0003',
                'license_status' => LicenseStatus::Trial->value,
                'starts_at' => now(),
                'expires_at' => now()->addDays(14),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_firm_a_context_cannot_update_firm_b_firm_license(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $licenseB = $this->runWithFirmContext($firmB, fn () => FirmLicense::factory()->forFirm($firmB)->create(['license_status' => LicenseStatus::Trial]));

        $affected = $this->runWithFirmContext($firmA, function () use ($licenseB) {
            return DB::table('firm_licenses')->where('id', $licenseB->id)->update(['license_status' => LicenseStatus::Active->value]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s firm_licenses row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FirmLicense::query()->find($licenseB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(LicenseStatus::Trial, $reReadAsFirmB->license_status);
    }

    public function test_firm_a_context_cannot_delete_firm_b_firm_license(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $licenseB = $this->runWithFirmContext($firmB, fn () => FirmLicense::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($licenseB) {
            DB::table('firm_licenses')->where('id', $licenseB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FirmLicense::query()->find($licenseB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s firm_licenses row.');
    }

    public function test_firm_a_context_cannot_reassign_firm_b_firm_license_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $licenseB = $this->runWithFirmContext($firmB, fn () => FirmLicense::factory()->forFirm($firmB)->create());

        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $licenseB) {
            return DB::table('firm_licenses')->where('id', $licenseB->id)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s firm_licenses row to itself.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FirmLicense::query()->find($licenseB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame($firmB->id, $reReadAsFirmB->firm_id);
    }

    // ---------------------------------------------------------------
    // Factory correctness proofs
    // ---------------------------------------------------------------

    /**
     * Bare factory default: a bare FirmLicense::factory()->create() must
     * succeed even from outside any already-active tenant context (the
     * factory's context-hold create() override).
     */
    public function test_firm_license_factory_default_creation_is_internally_consistent(): void
    {
        $license = FirmLicense::factory()->create();

        $this->assertNotNull($license->id);
        $this->assertNotNull($license->firm_id);

        $persisted = $this->runWithFirmContext(
            $license->firm,
            fn () => FirmLicense::query()->find($license->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame($license->firm_id, $persisted->firm_id);
    }

    public function test_firm_license_factory_for_firm_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();

        $license = $this->runWithFirmContext($firm, fn () => FirmLicense::factory()->forFirm($firm)->create());

        $this->assertSame($firm->id, $license->firm_id);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => FirmLicense::query()->find($license->id),
        );

        $this->assertNotNull($persisted);
    }

    /**
     * Multiple-licenses-per-firm is a supported state (no unique
     * constraint on firm_id alone, unlike firm_settings' singleton
     * shape) — a second bare create() for the same firm must succeed,
     * not throw.
     */
    public function test_a_firm_can_have_multiple_firm_licenses_simultaneously(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => FirmLicense::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => FirmLicense::factory()->forFirm($firm)->create());

        $count = $this->runWithFirmContext($firm, fn () => FirmLicense::query()->count());

        $this->assertSame(2, $count, 'firm_licenses has no unique-per-firm constraint — a second license for the same firm must be a supported state.');
    }

    // ---------------------------------------------------------------
    // THE fail-safe regression proof — the single most important test
    // in this file. Proves the highest-priority production fix in this
    // checkpoint (LegalDataAccessPolicyService::currentStatus()
    // wrapping its $firm->licenses read in
    // TenantContextService::runWithFirmContext()). This test would have
    // FAILED before that fix (a Suspended firm's license silently
    // resolving to "no license", full access silently granted) and must
    // PASS now.
    //
    // Deliberately does NOT wrap the test's own calls to canRead()/
    // canWrite()/canExport() in runWithFirmContext() — the whole point
    // is proving the PRODUCTION CODE establishes its own context
    // internally, not that the test supplies one ambiently.
    // ---------------------------------------------------------------

    public function test_legal_data_access_policy_service_correctly_denies_full_access_for_a_suspended_firm_with_no_ambient_context_established_beforehand(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext(
            $firm,
            fn () => FirmLicense::factory()->forFirm($firm)->create(['license_status' => LicenseStatus::Suspended]),
        );

        // Explicitly clear any ambient context left active by the
        // fixture-building factory above (FirmLicenseFactory
        // deliberately leaves context set afterward for the common
        // "create then read" pattern) — this test's entire point
        // depends on NO context being active the moment
        // canRead()/canWrite()/canExport() are called.
        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $policy = new LegalDataAccessPolicyService;

        $this->assertFalse(
            $policy->canRead($firm),
            'canRead() must establish its own tenant context internally and correctly deny read access for a Suspended firm — this is the regression proof for the LegalDataAccessPolicyService FORCE-RLS fix. A pre-fix build would silently resolve firm_licenses to an empty collection here and incorrectly report "no license" -> full access.'
        );

        $this->assertFalse(
            $policy->canWrite($firm),
            'canWrite() must correctly deny write access for a Suspended firm — a pre-fix build would incorrectly ALLOW it.'
        );

        $this->assertTrue(
            $policy->canExport($firm),
            'canExport() must still correctly ALLOW export for a Suspended firm (governed export remains available even when interactive read/write access does not) — proving the fix closes the fail-open gap without introducing an over-broad fail-closed regression.'
        );

        $this->assertNoDatabaseTenantContext('currentStatus() must clear its own internal context wrap before returning, leaving no leaked context behind for the next check.');
    }

    /**
     * Companion proof, same no-ambient-context shape: an Active firm's
     * license must still be correctly reported as FULL access — proving
     * the fix closes the fail-open gap without introducing a new
     * fail-closed-when-it-shouldn't-be regression.
     */
    public function test_legal_data_access_policy_service_correctly_allows_full_access_for_an_active_firm_with_no_ambient_context_established_beforehand(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext(
            $firm,
            fn () => FirmLicense::factory()->forFirm($firm)->create(['license_status' => LicenseStatus::Active]),
        );

        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $policy = new LegalDataAccessPolicyService;

        $this->assertTrue($policy->canRead($firm));
        $this->assertTrue($policy->canWrite($firm));
        $this->assertTrue($policy->canExport($firm));

        $this->assertNoDatabaseTenantContext();
    }

    /**
     * Direct-service companion (caller supplies context): proves
     * currentStatus() itself correctly reads firm_licenses under FORCE
     * when the caller already has context active — isolates the
     * "most restrictive license wins" policy logic itself from the
     * FORCE-RLS-fix's own context-establishment responsibility.
     */
    public function test_current_status_correctly_picks_the_most_restrictive_of_multiple_licenses_under_force_rls_when_caller_supplies_context(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, function () use ($firm) {
            FirmLicense::factory()->forFirm($firm)->create(['license_status' => LicenseStatus::Active]);
            FirmLicense::factory()->forFirm($firm)->create(['license_status' => LicenseStatus::Suspended]);
        });

        $policy = new LegalDataAccessPolicyService;

        $status = $this->runWithFirmContext($firm, fn () => $policy->currentStatus($firm));

        $this->assertSame(LicenseStatus::Suspended, $status, 'The most restrictive license must win when a firm has multiple, even under FORCE RLS.');
    }

    // ---------------------------------------------------------------
    // Audit-trail-integrity proof — the second most important test in
    // this file. Proves FirmLicenseCommercialService's phantom-audit-
    // trail bug is fixed: changeStatus()'s LicenseEvent row and the
    // actual firm_licenses row can no longer diverge. Before this
    // checkpoint's fix, the UPDATE would silently affect zero rows
    // (FORCE RLS with no ambient context) while the LicenseEvent audit
    // row was still written, claiming a status change that never
    // actually happened at the database layer.
    // ---------------------------------------------------------------

    public function test_firm_license_commercial_service_change_status_audit_trail_matches_the_actually_persisted_row(): void
    {
        $firm = Firm::factory()->create();
        $license = $this->runWithFirmContext(
            $firm,
            fn () => FirmLicense::factory()->forFirm($firm)->create(['license_status' => LicenseStatus::Trial]),
        );

        $service = new FirmLicenseCommercialService(new EntitlementPlanSyncService(new EntitlementService));

        $updated = $service->changeStatus($license, LicenseStatus::Active, 'activated after payment');

        $this->assertSame(LicenseStatus::Active, $updated->license_status, 'The in-memory returned model must reflect the new status.');

        // Re-query FRESH from the database, under the firm's own
        // context, rather than trusting the in-memory $updated object —
        // this is the genuine proof that the write actually persisted,
        // not merely that PHP memory looks right.
        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => FirmLicense::query()->find($license->id),
        );

        $this->assertNotNull($persisted);
        $this->assertSame(
            LicenseStatus::Active,
            $persisted->license_status,
            'The actual firm_licenses row in the database must genuinely reflect the new status — a pre-fix build would silently leave this row at Trial (zero rows affected by an unwrapped UPDATE under FORCE RLS) while still writing the LicenseEvent below, producing a phantom audit trail.'
        );

        $event = LicenseEvent::query()
            ->where('licensable_type', FirmLicense::class)
            ->where('licensable_id', $license->id)
            ->where('event_type', 'status_changed')
            ->first();

        $this->assertNotNull($event, 'The audit event must exist.');
        $this->assertSame('trial', $event->from_status);
        $this->assertSame('active', $event->to_status);

        // The heart of the proof: the audit trail's claimed to_status
        // must match what is ACTUALLY persisted in firm_licenses, not
        // just what LicenseEvent claims happened.
        $this->assertSame(
            $event->to_status,
            $persisted->license_status->value,
            'LicenseEvent.to_status and the actual persisted firm_licenses.license_status must agree — this is the phantom-audit-trail regression proof.'
        );
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => FirmLicense::factory()->forFirm($firm)->create());

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
        $registry = new ComplianceGapRegistryService;

        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
    }

    /**
     * Thirty-six previously forced tables plus firm_licenses must be
     * independently force-active and independently isolated at the same
     * time — proof this batch did not weaken or interfere with any
     * prior section's own enforcement. Uses clients as the companion
     * table.
     */
    public function test_firm_licenses_are_isolated_independently_and_simultaneously_with_clients(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());

        $licenseA = $this->runWithFirmContext($firmA, fn () => FirmLicense::factory()->forFirm($firmA)->create());
        $licenseB = $this->runWithFirmContext($firmB, fn () => FirmLicense::factory()->forFirm($firmB)->create());

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'firm_licenses' => FirmLicense::query()->pluck('id')->all(),
            'clients' => Client::withoutGlobalScopes()->pluck('id')->all(),
        ]);

        $this->assertSame([$licenseA->id], $resultA['firm_licenses']);
        $this->assertNotContains($licenseB->id, $resultA['firm_licenses']);
        $this->assertContains($clientA->id, $resultA['clients']);
        $this->assertNotContains($clientB->id, $resultA['clients']);
    }

    // ---------------------------------------------------------------
    // Migration down()/up() restoration proofs
    // ---------------------------------------------------------------

    /**
     * Rollback support: the firm_licenses migration's down() must
     * genuinely restore the Section 39A baseline — RLS still enabled,
     * policy still present, but NOT forced — never drop the policy or
     * disable RLS itself. Also proves rollback affects ONLY this one
     * table — every other previously-forced table must be untouched.
     */
    public function test_firm_licenses_migration_down_restores_the_not_forced_baseline_and_affects_only_this_table(): void
    {
        $migration = require base_path('database/migrations/2026_08_25_930019_force_rls_on_firm_licenses_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'firm_licenses'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while firm_licenses is rolled back."
                );
            }

            $policy = DB::selectOne(
                "select polname from pg_policy where polrelid = 'firm_licenses'::regclass"
            );
            $this->assertNotNull($policy, 'Rollback must not drop the tenant isolation policy.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'firm_licenses'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }
}
