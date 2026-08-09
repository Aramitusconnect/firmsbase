<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportEntityType;
use App\Enums\ImportRowStatus;
use App\Enums\ImportSourceType;
use App\Enums\TimeEntryStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ComplianceGapRegistryService;
use App\Services\DocumentUploadPolicyService;
use App\Services\EmployeeRateService;
use App\Services\ImportApplyService;
use App\Services\ImportAuditService;
use App\Services\ImportBatchService;
use App\Services\ImportDocumentSafetyService;
use App\Services\InvoiceDraftingService;
use App\Services\PaymentPlanService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use App\Services\TimeEntryApprovalService;
use App\Services\TimelineEventRecorder;
use App\Services\VirusScan\FakeVirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TimeEntriesForceRlsActivationTest — Section 39A-3L, Checkpoint 21.
 * Proves the thirty-ninth staged FORCE ROW LEVEL SECURITY activation
 * batch
 * (database/migrations/2026_08_25_930021_force_rls_on_time_entries_table.php)
 * is permanently active for time_entries and behaves correctly:
 * fail-closed with no context, correct cross-firm isolation, correct
 * same-firm access, that every previously-forced table (including
 * time_tracking_sessions, forced one checkpoint earlier) remains forced
 * simultaneously, and — the central finding of this checkpoint — that
 * TimeEntryApprovalService's five methods (createManualEntry, submit,
 * approve, reject, markInvoiced) genuinely persist their status
 * transitions to the database even when called with no ambient tenant
 * context, closing a real duplicate-billing risk without requiring any
 * change to InvoiceDraftingService.php itself.
 *
 * This checkpoint resolves the deliberate, documented asymmetry from
 * Checkpoint 20 (time_tracking_sessions forced, time_entries not yet
 * forced) — see
 * TimeTrackingSessionsForceRlsActivationTest::test_time_entries_and_time_tracking_sessions_are_now_both_forced_the_documented_asymmetry_resolved_in_checkpoint_21()
 * for the historical record of that asymmetry and its resolution here.
 *
 * time_entries carries THREE other tenant-owned relations of its own —
 * matter_id, client_id, and time_tracking_session_id — the same
 * "second, independently-resolved tenant-owned relation" shape as
 * document_chase_events' document_request_item_id (Checkpoint 17) and
 * time_tracking_sessions' own matter_id/client_id (Checkpoint 20). This
 * file proves the same honest boundary: RLS only ever validates a row's
 * OWN firm_id, never a related row's owning firm, so a raw insert whose
 * firm_id matches the active context but whose client_id points at a
 * CLIENT belonging to a different firm is NOT blocked by RLS. This is
 * documented here as a residual DATABASE-CONSTRAINT gap, never asserted
 * as something RLS itself closes.
 *
 * The single most important proof in this file is
 * test_invoice_drafting_from_time_entries_persists_invoiced_status_when_called_with_no_ambient_context_established_beforehand()
 * below: it is the regression proof for the highest-priority production
 * fix in this checkpoint. Before this checkpoint's fix,
 * InvoiceDraftingService::draftFromTimeEntries()'s call into
 * TimeEntryApprovalService::markInvoiced() against a now-FORCE-protected
 * time_entries row with no context established would have silently
 * updated ZERO rows (FORCE RLS's WITH CHECK/USING clauses filter it out
 * — never a raised error), leaving the entry still eligible to be
 * drafted onto a SECOND invoice later — a genuine double-billing risk in
 * a legal billing system. This test deliberately never wraps its own
 * call to draftFromTimeEntries() in any ambient context — the whole
 * point is proving markInvoiced()'s own internal self-wrap (which
 * becomes a savepoint inside draftFromTimeEntries()'s existing outer
 * DB::transaction()) makes the fix work transparently, without touching
 * InvoiceDraftingService.php at all.
 */
class TimeEntriesForceRlsActivationTest extends TestCase
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
        'firm_settings', 'firm_licenses', 'time_tracking_sessions',
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

    public function test_time_entries_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'time_entries'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_time_entries_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'time_entries'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'time_entries must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly thirty-nine tables (the thirty-eight previously forced
     * plus time_entries) must be FORCE-enabled among ALL prepared
     * tables — no more, no less.
     */
    public function test_exactly_thirty_nine_prepared_tables_are_force_row_level_security_enabled(): void
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
        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'time_entries', 'payment_plans', 'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations', 'integration_credentials', 'integration_oauth_states', 'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings', 'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events', 'integration_inbound_webhook_events', 'integration_connection_health', 'integration_usage_records', 'integration_provider_webhook_subscriptions', /* Narrowly updated by Checkpoint 4 (FirmsVault Live Integrations, "Plaid financial evidence add-on") -- this full-registry list must now also account for the 21 new tables Checkpoint 4 added to both PREPARED_TABLES and the FORCE-RLS rollout in the same migration (client_portal_matter_grants, the financial-evidence domain, and the provider-billing domain) -- additive only, no existing assertion removed or weakened. */ 'client_portal_matter_grants', 'financial_evidence_bank_accounts', 'financial_evidence_transactions', 'financial_evidence_income_records', 'financial_evidence_liabilities', 'financial_evidence_investment_records', 'financial_evidence_statements', 'financial_evidence_identity_records', 'provider_billable_call_reservations', 'provider_firm_operation_policies', 'provider_balance_snapshots', 'financial_evidence_matter_requests', 'financial_evidence_client_consents', 'financial_evidence_matter_authorizations', 'financial_evidence_matter_notes', 'financial_evidence_snapshots', 'financial_evidence_transaction_reviews', 'financial_evidence_duplicate_transfer_flags', 'financial_evidence_large_deposit_flags', 'financial_evidence_reconciliation_candidates', 'financial_account_reclassification_requests', 'accounting_journal_entries', 'accounting_postings', 'payment_allocations', 'payment_reversals', 'invoice_write_offs', 'accounting_periods']);
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
        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 23,
        // Table Phase C (this repo's forty-first staged FORCE
        // activation batch, covering payment_plan_events) for the
        // same reason — additive only, no existing assertion removed
        // or weakened.

        // Narrowly updated by Section 39A-3L, Checkpoint 26 (parties) for the same reason — additive only, no existing assertion removed or weakened.
        $this->assertSame(153, count($actuallyForced), 'Exactly thirty-nine prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 21 — no more, no less.');
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
        $forced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'time_entries', 'payment_plans', 'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations', 'integration_credentials', 'integration_oauth_states', 'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings', 'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events', 'integration_inbound_webhook_events', 'integration_connection_health', 'integration_usage_records', 'integration_provider_webhook_subscriptions', /* Narrowly updated by Checkpoint 4 (FirmsVault Live Integrations, "Plaid financial evidence add-on") -- this full-registry list must now also account for the 21 new tables Checkpoint 4 added to both PREPARED_TABLES and the FORCE-RLS rollout in the same migration (client_portal_matter_grants, the financial-evidence domain, and the provider-billing domain) -- additive only, no existing assertion removed or weakened. */ 'client_portal_matter_grants', 'financial_evidence_bank_accounts', 'financial_evidence_transactions', 'financial_evidence_income_records', 'financial_evidence_liabilities', 'financial_evidence_investment_records', 'financial_evidence_statements', 'financial_evidence_identity_records', 'provider_billable_call_reservations', 'provider_firm_operation_policies', 'provider_balance_snapshots', 'financial_evidence_matter_requests', 'financial_evidence_client_consents', 'financial_evidence_matter_authorizations', 'financial_evidence_matter_notes', 'financial_evidence_snapshots', 'financial_evidence_transaction_reviews', 'financial_evidence_duplicate_transfer_flags', 'financial_evidence_large_deposit_flags', 'financial_evidence_reconciliation_candidates', 'financial_account_reclassification_requests', 'accounting_journal_entries', 'accounting_postings', 'payment_allocations', 'payment_reversals', 'invoice_write_offs', 'accounting_periods']);
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
             where polrelid = 'time_entries'::regclass"
        );

        $this->assertNotNull($policy, 'The time_entries tenant isolation policy must still exist.');
        $this->assertStringContainsString('current_setting', $policy->using_expr);
        $this->assertStringContainsString('firm_id', $policy->using_expr);
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_time_entries(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => TimeEntry::factory()->forFirm($firm)->create());

        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, TimeEntry::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_time_entries(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('time_entries')->insert([
            'firm_id' => $firm->id,
            'user_id' => $user->id,
            'seconds' => 3600,
            'is_billable' => true,
            'worked_on' => now()->toDateString(),
            'status' => TimeEntryStatus::Draft->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_time_entry(): void
    {
        $firmA = Firm::factory()->create();
        $entryA = $this->runWithFirmContext($firmA, fn () => TimeEntry::factory()->forFirm($firmA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => TimeEntry::query()->pluck('id')->all(),
        );

        $this->assertSame([$entryA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_time_entry(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->runWithFirmContext($firmA, fn () => TimeEntry::factory()->forFirm($firmA)->create());
        $entryB = $this->runWithFirmContext($firmB, fn () => TimeEntry::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => TimeEntry::query()->pluck('id')->all(),
        );

        $this->assertNotContains($entryB->id, $visibleIds);
    }

    public function test_valid_insert_with_correct_firm_context_succeeds(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();

        $insertedId = $this->runWithFirmContext($firm, function () use ($firm, $user) {
            return DB::table('time_entries')->insertGetId([
                'firm_id' => $firm->id,
                'user_id' => $user->id,
                'seconds' => 3600,
                'is_billable' => true,
                'worked_on' => now()->toDateString(),
                'status' => TimeEntryStatus::Draft->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_context_cannot_insert_a_time_entry_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $user = User::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $user) {
            DB::table('time_entries')->insert([
                'firm_id' => $firmB->id,
                'user_id' => $user->id,
                'seconds' => 3600,
                'is_billable' => true,
                'worked_on' => now()->toDateString(),
                'status' => TimeEntryStatus::Draft->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_firm_a_context_cannot_update_firm_b_time_entry(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $entryB = $this->runWithFirmContext($firmB, fn () => TimeEntry::factory()->forFirm($firmB)->create(['status' => TimeEntryStatus::Draft]));

        $affected = $this->runWithFirmContext($firmA, function () use ($entryB) {
            return DB::table('time_entries')->where('id', $entryB->id)->update(['status' => TimeEntryStatus::Submitted->value]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s time_entries row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => TimeEntry::query()->find($entryB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(TimeEntryStatus::Draft, $reReadAsFirmB->status);
    }

    public function test_firm_a_context_cannot_delete_firm_b_time_entry(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $entryB = $this->runWithFirmContext($firmB, fn () => TimeEntry::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($entryB) {
            DB::table('time_entries')->where('id', $entryB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => TimeEntry::query()->find($entryB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s time_entries row.');
    }

    public function test_firm_a_context_cannot_reassign_firm_b_time_entry_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $entryB = $this->runWithFirmContext($firmB, fn () => TimeEntry::factory()->forFirm($firmB)->create());

        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $entryB) {
            return DB::table('time_entries')->where('id', $entryB->id)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s time_entries row to itself.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => TimeEntry::query()->find($entryB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame($firmB->id, $reReadAsFirmB->firm_id);
    }

    /**
     * Empirically proves the honest scope boundary described in this
     * file's class docblock and the migration's own docblock: RLS only
     * validates time_entries.firm_id, never client_id's OWN owning
     * firm — a raw insert whose firm_id matches the active context
     * still succeeds even when client_id points at a Client belonging
     * to a COMPLETELY DIFFERENT firm. This is a documented residual
     * DATABASE-CONSTRAINT gap, never to be described as blocked by RLS.
     */
    public function test_a_raw_insert_can_still_reference_a_client_from_a_different_firm_at_the_raw_db_layer(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $user = User::factory()->create();
        $foreignClient = $this->runWithFirmContext($otherFirm, fn () => Client::factory()->forFirm($otherFirm)->create());

        $mismatchedId = $this->runWithFirmContext($firm, function () use ($firm, $user, $foreignClient) {
            return DB::table('time_entries')->insertGetId([
                'firm_id' => $firm->id,
                'user_id' => $user->id,
                'client_id' => $foreignClient->id,
                'seconds' => 3600,
                'is_billable' => true,
                'worked_on' => now()->toDateString(),
                'status' => TimeEntryStatus::Draft->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt(
            $mismatchedId,
            'RLS only checks the row\'s own firm_id — a client_id belonging to a different firm is NOT blocked by RLS; this is a documented residual gap, not a false guarantee.'
        );
    }

    // ---------------------------------------------------------------
    // Factory correctness proofs
    // ---------------------------------------------------------------

    /**
     * Bare factory default: a bare TimeEntry::factory()->create() must
     * succeed even from outside any already-active tenant context (the
     * factory's context-hold create() override).
     */
    public function test_time_entry_factory_default_creation_is_internally_consistent(): void
    {
        $entry = TimeEntry::factory()->create();

        $this->assertNotNull($entry->id);
        $this->assertNotNull($entry->firm_id);

        $persisted = $this->runWithFirmContext(
            $entry->firm,
            fn () => TimeEntry::query()->find($entry->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame($entry->firm_id, $persisted->firm_id);
    }

    public function test_time_entry_factory_for_firm_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();

        $entry = $this->runWithFirmContext($firm, fn () => TimeEntry::factory()->forFirm($firm)->create());

        $this->assertSame($firm->id, $entry->firm_id);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => TimeEntry::query()->find($entry->id),
        );

        $this->assertNotNull($persisted);
    }

    /**
     * Explicit related-model factory state correctness: the approved()
     * state must correctly persist a coherent Approved entry under
     * FORCE RLS — status, approved_at, and billing_rate_cents_snapshot
     * all agree once genuinely read back from the database.
     */
    public function test_time_entry_factory_approved_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();

        $entry = $this->runWithFirmContext($firm, fn () => TimeEntry::factory()->forFirm($firm)->approved(30000)->create());

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => TimeEntry::query()->find($entry->id),
        );

        $this->assertNotNull($persisted);
        $this->assertSame(TimeEntryStatus::Approved, $persisted->status);
        $this->assertSame(30000, $persisted->billing_rate_cents_snapshot);
        $this->assertNotNull($persisted->approved_at);
        $this->assertTrue($persisted->isEligibleForInvoicing());
    }

    /**
     * Multiple entries per firm is a supported state — a second bare
     * create() for the same firm must succeed, not throw.
     */
    public function test_a_firm_can_have_multiple_time_entries_simultaneously(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => TimeEntry::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => TimeEntry::factory()->forFirm($firm)->create());

        $count = $this->runWithFirmContext($firm, fn () => TimeEntry::query()->count());

        $this->assertSame(2, $count, 'time_entries has no unique-per-firm constraint — a second entry for the same firm must be a supported state.');
    }

    // ---------------------------------------------------------------
    // (a) THE fail-safe regression proof — the single most important
    // test in this file. Proves the highest-priority production fix in
    // this checkpoint (TimeEntryApprovalService::markInvoiced() self-
    // wrapping its body in TenantContextService::runWithFirmContext(),
    // whose internal transaction correctly becomes a savepoint when
    // called from inside InvoiceDraftingService::draftFromTimeEntries()'s
    // own outer DB::transaction()). This test would have FAILED before
    // that fix (the entry silently remaining un-invoiced in the
    // database, eligible to be drafted onto a second invoice later) and
    // must PASS now — without InvoiceDraftingService.php itself ever
    // being touched.
    //
    // Deliberately does NOT wrap the test's own call to
    // draftFromTimeEntries() in any ambient context — the whole point
    // is proving the PRODUCTION CODE (markInvoiced()'s self-wrap)
    // establishes its own context internally, not that the test
    // spoon-feeds it one.
    // ---------------------------------------------------------------

    public function test_invoice_drafting_from_time_entries_persists_invoiced_status_when_called_with_no_ambient_context_established_beforehand(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $entry = $this->runWithFirmContext($firm, fn () => TimeEntry::factory()->forFirm($firm)->create([
            'client_id' => $client->id,
            'status' => TimeEntryStatus::Approved,
            'is_billable' => true,
            'seconds' => 3600,
            'billing_rate_cents_snapshot' => 20000,
        ]));

        // Explicitly clear any ambient context left active by the
        // fixture-building factory calls above — this test's entire
        // point depends on NO context being active the moment
        // draftFromTimeEntries() is called.
        (new TenantContextService)->clearDatabaseTenantContext();
        (new TenantContextService)->clearFirmContext();
        $this->assertNoDatabaseTenantContext();

        $service = new InvoiceDraftingService(
            new TimeEntryApprovalService(new EmployeeRateService),
            new TimelineEventRecorder,
        );

        $invoice = $service->draftFromTimeEntries($firm, $client, [$entry]);

        $this->assertNotNull($invoice->id, 'draftFromTimeEntries() must still create the Invoice — this is the part that worked even before the fix.');

        $this->assertNoDatabaseTenantContext(
            'draftFromTimeEntries() must clear its own internal context wrap(s) before returning, leaving no leaked context behind for the next check.'
        );

        // Re-read FRESH from the database, under the firm's own
        // (freshly re-established) context, rather than trusting the
        // in-memory $entry object — this is the genuine proof that the
        // UPDATE actually persisted, not merely that PHP memory looks
        // right or that an Invoice happened to get created.
        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => TimeEntry::query()->find($entry->id),
        );

        $this->assertNotNull($persisted);
        $this->assertSame(
            TimeEntryStatus::Invoiced,
            $persisted->status,
            'The actual time_entries row in the database must genuinely be Invoiced — a pre-fix build would silently leave this row Approved (zero rows affected by an unwrapped UPDATE under FORCE RLS) while still creating the Invoice above, the exact double-billing risk this checkpoint closes.'
        );
    }

    /**
     * (b) Double-billing guard proof: after the entry above has been
     * genuinely marked Invoiced in the database, it must no longer be
     * eligible for invoicing, and a second draftFromTimeEntries() call
     * against it must throw rather than silently drafting it again onto
     * a second invoice.
     */
    public function test_an_already_invoiced_time_entry_cannot_be_drafted_a_second_time(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $entry = $this->runWithFirmContext($firm, fn () => TimeEntry::factory()->forFirm($firm)->create([
            'client_id' => $client->id,
            'status' => TimeEntryStatus::Approved,
            'is_billable' => true,
            'seconds' => 3600,
            'billing_rate_cents_snapshot' => 20000,
        ]));

        $service = new InvoiceDraftingService(
            new TimeEntryApprovalService(new EmployeeRateService),
            new TimelineEventRecorder,
        );

        $this->runWithFirmContext($firm, fn () => $service->draftFromTimeEntries($firm, $client, [$entry]));

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => TimeEntry::query()->find($entry->id),
        );

        $this->assertSame(TimeEntryStatus::Invoiced, $persisted->status);
        $this->assertFalse($persisted->isEligibleForInvoicing(), 'An already-invoiced entry must never report itself eligible for invoicing again.');

        $this->expectException(\RuntimeException::class);

        $service->draftFromTimeEntries($firm, $client, [$persisted]);
    }

    // ---------------------------------------------------------------
    // (c) Import-apply regression proof
    // ---------------------------------------------------------------

    public function test_import_apply_creates_a_correctly_owned_time_entry_when_called_with_no_ambient_context_established_beforehand(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();

        $auditService = new ImportAuditService;
        $batchService = new ImportBatchService($auditService);
        $documentSafetyService = new ImportDocumentSafetyService(new DocumentUploadPolicyService, new FakeVirusScanner);
        $applyService = new ImportApplyService($documentSafetyService, $auditService, app(InvoiceDraftingService::class), app(PaymentPlanService::class));

        $batch = $batchService->create($firm, ImportEntityType::TimeEntry, ImportSourceType::CsvUpload);
        // import_batches gained permanent FORCE ROW LEVEL SECURITY in a
        // later, separate wave (Section 39A-9 Wave 9); stageRows()'s own
        // wrap already restores database session context to "none" by
        // the time it returns, so a bare $batch->fresh() call afterward
        // would return null. Chain stageRows()'s own already-fresh
        // return value and confirmBatch()'s own return value instead of
        // an unwrapped re-fetch.
        $batch = $batchService->stageRows($batch, [[
            'user_id' => $user->id,
            'seconds' => 5400,
            'worked_on' => now()->toDateString(),
        ]]);
        $batch->rows()->update(['status' => ImportRowStatus::Validated->value]);
        $confirmed = $applyService->confirmBatch($batch);

        // No ambient context established before apply() — the whole
        // point is proving ImportApplyService's own internal wrap (the
        // one-line fix around the TimeEntry match arm) makes the write
        // succeed transparently.
        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $applied = $applyService->apply($confirmed);

        $this->assertSame(ImportBatchStatus::Applied, $applied->status);

        $row = $batch->rows()->first();
        $this->assertSame(
            ImportRowStatus::Applied,
            $row->status,
            'The row must genuinely apply, not silently Fail, now that time_entries is FORCE RLS protected.'
        );
        $this->assertNotNull($row->applied_record_id);

        $persistedEntry = $this->runWithFirmContext(
            $firm,
            fn () => TimeEntry::query()->find($row->applied_record_id),
        );

        $this->assertNotNull($persistedEntry, 'A real, correctly-owned time_entries row must be visible under the firm\'s own context afterward.');
        $this->assertSame($firm->id, $persistedEntry->firm_id);
        $this->assertSame(5400, $persistedEntry->seconds);
    }

    // ---------------------------------------------------------------
    // (d) Approval-audit-integrity proof
    // ---------------------------------------------------------------

    public function test_submit_approve_and_reject_genuinely_persist_when_called_with_no_ambient_context_established_beforehand(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();
        $approver = User::factory()->create();

        $service = new TimeEntryApprovalService(new EmployeeRateService);

        // --- submit() ---
        $entry = $this->runWithFirmContext(
            $firm,
            fn () => TimeEntry::factory()->forFirm($firm)->forUser($user)->create(['status' => TimeEntryStatus::Draft]),
        );

        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $submitted = $service->submit($entry);
        $this->assertSame(TimeEntryStatus::Submitted, $submitted->status);
        $this->assertNoDatabaseTenantContext('submit() must clear its own internal context wrap before returning.');

        $persistedSubmitted = $this->runWithFirmContext(
            $firm,
            fn () => TimeEntry::query()->find($entry->id),
        );
        $this->assertSame(TimeEntryStatus::Submitted, $persistedSubmitted->status, 'submit() must genuinely persist to the database, not just return an in-memory object.');

        // --- approve() ---
        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $approved = $service->approve($persistedSubmitted, $approver);
        $this->assertSame(TimeEntryStatus::Approved, $approved->status);
        $this->assertNoDatabaseTenantContext('approve() must clear its own internal context wrap(s) before returning.');

        $persistedApproved = $this->runWithFirmContext(
            $firm,
            fn () => TimeEntry::query()->find($entry->id),
        );
        $this->assertSame(TimeEntryStatus::Approved, $persistedApproved->status, 'approve() must genuinely persist to the database, not just return an in-memory object.');
        $this->assertSame($approver->id, $persistedApproved->approved_by);

        // --- reject() — uses a second, independent Submitted entry,
        // since the one above is now Approved (reject() requires
        // Submitted). ---
        $draftForReject = $this->runWithFirmContext(
            $firm,
            fn () => TimeEntry::factory()->forFirm($firm)->forUser($user)->create(['status' => TimeEntryStatus::Draft]),
        );

        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $service->submit($draftForReject);
        $this->assertNoDatabaseTenantContext();

        $persistedSubmittedForReject = $this->runWithFirmContext(
            $firm,
            fn () => TimeEntry::query()->find($draftForReject->id),
        );
        $this->assertSame(TimeEntryStatus::Submitted, $persistedSubmittedForReject->status);

        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $rejected = $service->reject($persistedSubmittedForReject, $approver, 'Missing matter reference');
        $this->assertSame(TimeEntryStatus::Rejected, $rejected->status);
        $this->assertNoDatabaseTenantContext('reject() must clear its own internal context wrap before returning.');

        $persistedRejected = $this->runWithFirmContext(
            $firm,
            fn () => TimeEntry::query()->find($draftForReject->id),
        );
        $this->assertSame(TimeEntryStatus::Rejected, $persistedRejected->status, 'reject() must genuinely persist to the database, not just return an in-memory object.');
        $this->assertSame('Missing matter reference', $persistedRejected->rejected_reason);
    }

    public function test_create_manual_entry_genuinely_persists_when_called_with_no_ambient_context_established_beforehand(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $service = new TimeEntryApprovalService(new EmployeeRateService);
        $entry = $service->createManualEntry($firm, $user, 1800, now());

        $this->assertNotNull($entry->id);
        $this->assertSame(TimeEntryStatus::Draft, $entry->status);
        $this->assertNoDatabaseTenantContext('createManualEntry() must clear its own internal context wrap before returning.');

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => TimeEntry::query()->find($entry->id),
        );

        $this->assertNotNull($persisted, 'createManualEntry() must genuinely persist to the database, not just return an in-memory object.');
        $this->assertSame($firm->id, $persisted->firm_id);
        $this->assertSame(1800, $persisted->seconds);
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => TimeEntry::factory()->forFirm($firm)->create());

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
     * Thirty-eight previously forced tables plus time_entries must be
     * independently force-active and independently isolated at the
     * same time — proof this batch did not weaken or interfere with any
     * prior section's own enforcement. Uses clients as the companion
     * table.
     */
    public function test_time_entries_are_isolated_independently_and_simultaneously_with_clients(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());

        $entryA = $this->runWithFirmContext($firmA, fn () => TimeEntry::factory()->forFirm($firmA)->create());
        $entryB = $this->runWithFirmContext($firmB, fn () => TimeEntry::factory()->forFirm($firmB)->create());

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'time_entries' => TimeEntry::query()->pluck('id')->all(),
            'clients' => Client::withoutGlobalScopes()->pluck('id')->all(),
        ]);

        $this->assertSame([$entryA->id], $resultA['time_entries']);
        $this->assertNotContains($entryB->id, $resultA['time_entries']);
        $this->assertContains($clientA->id, $resultA['clients']);
        $this->assertNotContains($clientB->id, $resultA['clients']);
    }

    // ---------------------------------------------------------------
    // Migration down()/up() restoration proofs
    // ---------------------------------------------------------------

    /**
     * Rollback support: the time_entries migration's down() must
     * genuinely restore the Section 39A baseline — RLS still enabled,
     * policy still present, but NOT forced — never drop the policy or
     * disable RLS itself. Also proves rollback affects ONLY this one
     * table — every other previously-forced table must be untouched.
     */
    public function test_time_entries_migration_down_restores_the_not_forced_baseline_and_affects_only_this_table(): void
    {
        $migration = require base_path('database/migrations/2026_08_25_930021_force_rls_on_time_entries_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'time_entries'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while time_entries is rolled back."
                );
            }

            $policy = DB::selectOne(
                "select polname from pg_policy where polrelid = 'time_entries'::regclass"
            );
            $this->assertNotNull($policy, 'Rollback must not drop the tenant isolation policy.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'time_entries'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }
}
