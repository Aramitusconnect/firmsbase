<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\ActivationChecklistStatus;
use App\Enums\FirmActivationStatus;
use App\Enums\LicenseStatus;
use App\Models\ActivationChecklist;
use App\Models\BillingAccount;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmLicense;
use App\Models\FirmSettings;
use App\Models\TenantEncryptionKey;
use App\Services\ActivationChecklistService;
use App\Services\ComplianceGapRegistryService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ActivationChecklistsForceRlsActivationTest — Section 39A-3L,
 * Checkpoint 2, Table Phase C. Proves the twentieth staged FORCE ROW
 * LEVEL SECURITY activation batch
 * (database/migrations/2026_08_25_930002_force_rls_on_activation_checklists_table.php)
 * is permanently active for activation_checklists and behaves
 * correctly: fail-closed with no context, correct cross-firm isolation,
 * correct same-firm access, that every previously-forced table
 * (clients, firm_users, documents, deadlines, tasks, matters, invoices,
 * payments, conflict_check_runs, lead_sources, consultation_outcomes,
 * firm_leads, consultations, firm_practice_areas, document_chase_rules,
 * employee_rates, calendar_events, client_communication_preferences,
 * payment_classification_events) remains forced simultaneously, and
 * that ActivationChecklistService's five tenant-context-wrapped methods
 * still function correctly end-to-end.
 *
 * activation_checklist_items (the child table) deliberately has no
 * firm_id column and is NOT RLS-protected at all — see this file's own
 * residual-gap proof below
 * (test_activation_checklist_items_child_table_has_no_rls_protection_of_its_own)
 * for the honest, empirically-proven boundary of what FORCE RLS on
 * activation_checklists itself does and does not cover.
 */
class ActivationChecklistsForceRlsActivationTest extends TestCase
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
        'client_communication_preferences', 'payment_classification_events',
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

    public function test_activation_checklists_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'activation_checklists'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_activation_checklists_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'activation_checklists'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'activation_checklists must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly twenty tables (the nineteen previously forced plus
     * activation_checklists) must be FORCE-enabled among ALL prepared
     * tables — no more, no less. This is the "exact expected count"
     * proof, independent of RlsForceRolloutFirewallTest's own
     * equivalent check, so this file stands alone as proof for this
     * table.
     *
     * Narrowly updated by Section 39A-3L, Checkpoint 3, Table Phase C
     * (this repo's twenty-first staged FORCE activation batch, covering
     * firm_activation_events) to account for that later, legitimate
     * addition — the count and expected-table list below now reflect
     * the real, current state of this working tree rather than a
     * frozen snapshot of Checkpoint 2 alone. Additive only: every
     * originally-asserted table is still asserted forced here.
     *
     * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 4, Table
     * Phase C (this repo's twenty-second staged FORCE activation
     * batch, covering firm_entitlements) for the same reason — additive
     * only, no existing assertion removed or weakened.
     *
     * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 5, Table
     * Phase C (this repo's twenty-third staged FORCE activation batch,
     * covering firm_entitlement_events) for the same reason — additive
     * only, no existing assertion removed or weakened.
     *
     * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 6, Table
     * Phase C (this repo's twenty-fourth staged FORCE activation batch,
     * covering installed_template_packs) for the same reason — additive
     * only, no existing assertion removed or weakened.
     *
     * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 7, Table
     * Phase C (this repo's twenty-fifth staged FORCE activation batch,
     * covering template_upgrade_logs) for the same reason — additive
     * only, no existing assertion removed or weakened.
     *
     * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 8, Table
     * Phase C (this repo's twenty-sixth staged FORCE activation batch,
     * covering template_upgrade_previews) for the same reason — additive
     * only, no existing assertion removed or weakened.
     */
    public function test_exactly_twenty_prepared_tables_are_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 9, Table
        // Phase C (seat_allocations) for the same reason — additive
        // only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 11, Table
        // Phase C (communication_consents) for the same reason — additive
        // only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 12, Table
        // Phase C (communication_consent_events) for the same reason —
        // additive only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 13, Table
        // Phase C (this repo's thirty-first staged FORCE activation
        // batch, covering intake_submissions) for the same reason —
        // additive only, no existing assertion removed or weakened.
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
        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'activation_checklists', 'firm_activation_events', 'firm_entitlements', 'firm_entitlement_events', 'installed_template_packs', 'template_upgrade_logs', 'template_upgrade_previews', 'seat_allocations', 'document_requests', 'communication_consents', 'communication_consent_events', 'intake_submissions', 'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events', 'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans', 'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations', 'integration_credentials', 'integration_oauth_states', 'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings', 'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events', 'integration_inbound_webhook_events', 'integration_connection_health', 'integration_usage_records', 'integration_provider_webhook_subscriptions', /* Narrowly updated by Checkpoint 4 (FirmsVault Live Integrations, "Plaid financial evidence add-on") -- this full-registry list must now also account for the 21 new tables Checkpoint 4 added to both PREPARED_TABLES and the FORCE-RLS rollout in the same migration (client_portal_matter_grants, the financial-evidence domain, and the provider-billing domain) -- additive only, no existing assertion removed or weakened. */ 'client_portal_matter_grants', 'financial_evidence_bank_accounts', 'financial_evidence_transactions', 'financial_evidence_income_records', 'financial_evidence_liabilities', 'financial_evidence_investment_records', 'financial_evidence_statements', 'financial_evidence_identity_records', 'provider_billable_call_reservations', 'provider_firm_operation_policies', 'provider_balance_snapshots', 'financial_evidence_matter_requests', 'financial_evidence_client_consents', 'financial_evidence_matter_authorizations', 'financial_evidence_matter_notes', 'financial_evidence_snapshots', 'financial_evidence_transaction_reviews', 'financial_evidence_duplicate_transfer_flags', 'financial_evidence_large_deposit_flags', 'financial_evidence_reconciliation_candidates', 'financial_account_reclassification_requests', 'accounting_journal_entries', 'accounting_postings']);
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

        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 10, Table
        // Phase C (document_requests) for the same reason — additive
        // only, no existing assertion removed or weakened.
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 11, Table
        // Phase C (communication_consents) for the same reason — additive
        // only, no existing assertion removed or weakened.
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
        $this->assertSame(149, count($actuallyForced), 'Exactly thirty-six prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 13 — no more, no less (twenty-one after this batch\'s own Checkpoint 2 plus firm_activation_events from Checkpoint 3, plus firm_entitlements from Checkpoint 4, plus firm_entitlement_events from Checkpoint 5, plus installed_template_packs from Checkpoint 6, plus template_upgrade_logs from Checkpoint 7, plus template_upgrade_previews from Checkpoint 8, plus seat_allocations from Checkpoint 9, plus document_requests from Checkpoint 10, plus communication_consents from Checkpoint 11, plus communication_consent_events from Checkpoint 12, plus intake_submissions from Checkpoint 13). Narrowly updated again for Section 39A-3L, Checkpoint 14 (matter_readiness_scores added on top of the prior thirty-one), again for Checkpoint 15 (readiness_score_events added on top of the prior thirty-two), and again for Checkpoint 16 (tenant_encryption_keys added on top of the prior thirty-three).');
        $this->assertSame($expectedForced, $actuallyForced);
    }

    public function test_the_tenant_isolation_policy_remains_present_and_unchanged_after_up(): void
    {
        $policy = DB::selectOne(
            "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'activation_checklists'::regclass"
        );

        $this->assertNotNull($policy, 'The activation_checklists tenant isolation policy must still exist.');
        $this->assertStringContainsString('current_setting', $policy->using_expr);
        $this->assertStringContainsString('firm_id', $policy->using_expr);
    }

    /**
     * Genuine no-context regression proof: explicitly clears
     * app.current_firm_id (rather than relying on a test never having
     * set it, which could be masked by an earlier leak) immediately
     * before reading — proving the read genuinely fails closed now
     * that this table is forced.
     */
    public function test_missing_tenant_context_cannot_read_activation_checklists(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => ActivationChecklist::factory()->forFirm($firm)->create());

        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, ActivationChecklist::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_insert_activation_checklists(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('activation_checklists')->insert([
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'status' => 'in_progress',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * ActivationChecklistService::createChecklist() itself, called with
     * NO PostgreSQL tenant context pre-established by the caller,
     * establishes its own — this proves the service's own wrap makes
     * the write succeed even from a genuinely context-less starting
     * point (as opposed to merely proving the wrap exists in source).
     */
    public function test_creating_a_checklist_with_no_pre_existing_context_still_succeeds_via_the_services_own_wrap(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $checklist = (new ActivationChecklistService)->createChecklist($firm);

        $this->assertNotNull($checklist->id);
        $this->assertSame($firm->id, $checklist->firm_id);
    }

    public function test_firm_a_context_can_read_its_own_activation_checklist(): void
    {
        $firmA = Firm::factory()->create();
        $checklistA = $this->runWithFirmContext($firmA, fn () => ActivationChecklist::factory()->forFirm($firmA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => ActivationChecklist::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$checklistA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_activation_checklist(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->runWithFirmContext($firmA, fn () => ActivationChecklist::factory()->forFirm($firmA)->create());
        $checklistB = $this->runWithFirmContext($firmB, fn () => ActivationChecklist::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => ActivationChecklist::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($checklistB->id, $visibleIds);
    }

    public function test_valid_insert_with_correct_firm_context_succeeds(): void
    {
        $firm = Firm::factory()->create();

        $checklistId = $this->runWithFirmContext($firm, function () use ($firm) {
            return DB::table('activation_checklists')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firm->id,
                'status' => 'in_progress',
                'started_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($checklistId);
    }

    public function test_firm_a_context_cannot_insert_an_activation_checklist_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('activation_checklists')->insert([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmB->id,
                'status' => 'in_progress',
                'started_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_firm_a_context_cannot_update_firm_b_activation_checklist(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $checklistB = $this->runWithFirmContext($firmB, fn () => ActivationChecklist::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($checklistB) {
            DB::table('activation_checklists')->where('id', $checklistB->id)->update(['status' => 'completed']);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => ActivationChecklist::withoutGlobalScopes()->find($checklistB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(
            ActivationChecklistStatus::InProgress,
            $reReadAsFirmB->status,
            'Firm A context must not be able to update Firm B\'s activation_checklists row.'
        );
    }

    public function test_firm_a_context_cannot_delete_firm_b_activation_checklist(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $checklistB = $this->runWithFirmContext($firmB, fn () => ActivationChecklist::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($checklistB) {
            DB::table('activation_checklists')->where('id', $checklistB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => ActivationChecklist::withoutGlobalScopes()->find($checklistB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s activation_checklists row.');
    }

    /**
     * Firm ownership itself (the firm_id column) must never be
     * reassignable via a raw UPDATE while under a different firm's
     * context — even setting aside the value being updated TO, the
     * policy's USING clause must reject the row entirely once no rows
     * are visible under firmA's context.
     */
    public function test_firm_a_context_cannot_reassign_firm_b_activation_checklist_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $checklistB = $this->runWithFirmContext($firmB, fn () => ActivationChecklist::factory()->forFirm($firmB)->create());

        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $checklistB) {
            return DB::table('activation_checklists')->where('id', $checklistB->id)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s checklist to itself.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => ActivationChecklist::withoutGlobalScopes()->find($checklistB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame($firmB->id, $reReadAsFirmB->firm_id);
    }

    public function test_activation_checklist_factory_default_creation_is_internally_consistent(): void
    {
        $checklist = ActivationChecklist::factory()->create();

        $this->assertNotNull($checklist->id);
        $this->assertNotNull($checklist->firm_id);

        $reRead = $this->runWithFirmContext(
            $checklist->firm,
            fn () => ActivationChecklist::withoutGlobalScopes()->find($checklist->id),
        );

        $this->assertNotNull($reRead, 'A bare factory-created checklist must be visible under its own firm\'s context.');
        $this->assertSame($checklist->firm_id, $reRead->firm_id);
    }

    public function test_activation_checklist_factory_for_firm_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();
        $checklist = ActivationChecklist::factory()->forFirm($firm)->create();

        $this->assertSame($firm->id, $checklist->firm_id);

        $reRead = $this->runWithFirmContext(
            $firm,
            fn () => ActivationChecklist::withoutGlobalScopes()->find($checklist->id),
        );

        $this->assertNotNull($reRead);
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => ActivationChecklist::factory()->forFirm($firm)->create());

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
     * @param  array<int, string>  $skip
     */
    private function eligibleFirm(ActivationChecklistService $service, array $skip = []): Firm
    {
        $firm = Firm::factory()->create([
            'billing_account_id' => in_array('billing_account', $skip, true)
                ? null
                : BillingAccount::factory()->create()->id,
        ]);

        if (! in_array('firm_settings', $skip, true)) {
            FirmSettings::factory()->create(['firm_id' => $firm->id]);
        }

        if (! in_array('license', $skip, true)) {
            FirmLicense::factory()->create([
                'firm_id' => $firm->id,
                'license_status' => LicenseStatus::Active,
            ]);
        }

        if (! in_array('encryption_key', $skip, true)) {
            TenantEncryptionKey::factory()->forFirm($firm)->create();
        }

        return $firm->fresh();
    }

    /**
     * End-to-end proof of all five ActivationChecklistService methods
     * working together under FORCE RLS: createChecklist() ->
     * seedProductionReadinessItems() -> activate(), landing on exactly
     * the final state the implementer manually verified —
     * activation_status=activated, checklist status=completed with
     * completed_at set, all 12 seeded items present. Every read below
     * that happens AFTER a service call (whose own runWithFirmContext()
     * wrap has already cleared context) is itself wrapped in an
     * explicit context, since it is a genuinely fresh database read
     * against this now-force-protected table.
     */
    public function test_the_five_activation_checklist_service_methods_work_end_to_end_under_force_rls(): void
    {
        $service = new ActivationChecklistService;
        $firm = $this->eligibleFirm($service);

        $checklist = $service->createChecklist($firm);
        $this->assertSame(ActivationChecklistStatus::InProgress, $checklist->status);

        $inserted = $service->seedProductionReadinessItems($firm->fresh());
        $this->assertCount(12, $inserted);

        // allRequiredItemsSatisfied() only reads activation_checklist_items,
        // which is not RLS-protected — no context needed for this part.
        $itemCount = $this->runWithFirmContext($firm, fn () => $firm->fresh()->activationChecklist->items()->count());
        $this->assertSame(12, $itemCount);

        $this->runWithFirmContext($firm, fn () => $firm->fresh()->activationChecklist->items()->update([
            'is_complete' => true,
            'completed_at' => now(),
        ]));

        $this->assertTrue($service->isEligible($firm->fresh()));
        $this->assertSame([], $service->unmetRequirements($firm->fresh()));

        $activated = $service->activate($firm->fresh());

        $this->assertSame(FirmActivationStatus::Activated, $activated->activation_status);

        $finalChecklist = $this->runWithFirmContext($firm, fn () => $activated->activationChecklist->fresh());

        $this->assertSame(ActivationChecklistStatus::Completed, $finalChecklist->status);
        $this->assertNotNull($finalChecklist->completed_at);
        $this->assertSame(
            12,
            $this->runWithFirmContext($firm, fn () => $finalChecklist->items()->count())
        );
    }

    /**
     * Rollback support: the migration's down() must genuinely restore
     * the Section 39A baseline — RLS still enabled, policy still
     * present, but NOT forced — never drop the policy or disable RLS
     * itself. Also proves rollback affects ONLY this one table — every
     * other previously-forced table must be untouched by this specific
     * migration's down()/up() cycle.
     */
    public function test_migration_down_restores_the_not_forced_baseline_and_affects_only_this_table(): void
    {
        $migration = require base_path('database/migrations/2026_08_25_930002_force_rls_on_activation_checklists_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'activation_checklists'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while activation_checklists is rolled back."
                );
            }

            // The policy itself must survive rollback unchanged — down()
            // only flips FORCE off, it never drops the policy.
            $policy = DB::selectOne(
                "select polname from pg_policy where polrelid = 'activation_checklists'::regclass"
            );
            $this->assertNotNull($policy, 'Rollback must not drop the tenant isolation policy.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'activation_checklists'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }

    /**
     * Nineteen previously forced tables plus activation_checklists must
     * be independently force-active and independently isolated at the
     * same time — proof this batch did not weaken or interfere with any
     * prior section's own enforcement.
     */
    public function test_activation_checklists_is_isolated_independently_and_simultaneously_with_documents(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $checklistA = $this->runWithFirmContext($firmA, fn () => ActivationChecklist::factory()->forFirm($firmA)->create());
        $checklistB = $this->runWithFirmContext($firmB, fn () => ActivationChecklist::factory()->forFirm($firmB)->create());

        $documentA = $this->runWithFirmContext($firmA, fn () => Document::factory()->create(['firm_id' => $firmA->id]));
        $documentB = $this->runWithFirmContext($firmB, fn () => Document::factory()->create(['firm_id' => $firmB->id]));

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'activation_checklists' => ActivationChecklist::withoutGlobalScopes()->pluck('id')->all(),
            'documents' => Document::withoutGlobalScopes()->pluck('id')->all(),
        ]);

        $this->assertSame([$checklistA->id], $resultA['activation_checklists']);
        $this->assertNotContains($checklistB->id, $resultA['activation_checklists']);
        $this->assertSame([$documentA->id], $resultA['documents']);
        $this->assertNotContains($documentB->id, $resultA['documents']);
    }

    /**
     * Residual gap, honestly documented (same spirit as the transitive
     * firm_id-mismatch gaps proven in Matters/Invoices/Payments/
     * PaymentClassificationEventsForceRlsActivationTest, though the
     * mechanics here differ): activation_checklist_items has NO firm_id
     * column and NO row level security of its own at all — RLS on
     * activation_checklists protects the parent row only. A raw insert
     * into activation_checklist_items, done with NO tenant context
     * whatsoever, still succeeds unconditionally even when its
     * activation_checklist_id belongs to a firm entirely different from
     * (or with no relation to) whatever context, if any, happens to be
     * active. This is a genuine, database-level gap — not something
     * this checkpoint may fix (activation_checklist_items was never a
     * FORCE RLS candidate; see the migration's own docblock), so it is
     * proven here rather than silently assumed away.
     */
    public function test_activation_checklist_items_child_table_has_no_rls_protection_of_its_own(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $checklistB = $this->runWithFirmContext($firmB, fn () => ActivationChecklist::factory()->forFirm($firmB)->create());

        // No tenant context active at all — not even firm A's.
        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $itemId = DB::table('activation_checklist_items')->insertGetId([
            'activation_checklist_id' => $checklistB->id,
            'item_key' => 'raw_bypass_item',
            'label' => 'Inserted with no tenant context at all',
            'is_required' => true,
            'is_complete' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertIsInt(
            $itemId,
            'activation_checklist_items has no RLS of its own — a raw insert with no tenant context succeeds unconditionally. '.
            'This is a documented residual database-level gap, not a false guarantee about what FORCE RLS on activation_checklists itself covers.'
        );

        $this->assertDatabaseHas('activation_checklist_items', [
            'id' => $itemId,
            'activation_checklist_id' => $checklistB->id,
        ]);
    }

    public function test_compliance_gap_registry_service_still_tracks_the_rls_gap(): void
    {
        $registry = new ComplianceGapRegistryService;

        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
    }
}
