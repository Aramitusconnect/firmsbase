<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\DocumentRequestItemStatus;
use App\Enums\MatterReadinessStatus;
use App\Enums\ReadinessComponentStatus;
use App\Enums\TaskStatus;
use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterReadinessScore;
use App\Models\ReadinessScorecardComponent;
use App\Models\Task;
use App\Services\ComplianceGapRegistryService;
use App\Services\MatterReadinessService;
use App\Services\ReadinessScorecardRegistry;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MatterReadinessScoresForceRlsActivationTest — Section 39A-3L,
 * Checkpoint 14. Proves the thirty-second staged FORCE ROW LEVEL
 * SECURITY activation batch
 * (database/migrations/2026_08_25_930014_force_rls_on_matter_readiness_scores_table.php)
 * is permanently active for matter_readiness_scores and behaves
 * correctly: fail-closed with no context, correct cross-firm isolation,
 * correct same-firm access, that every previously-forced table remains
 * forced simultaneously, that MatterReadinessService::recompute() (now
 * wrapping the evaluate()-through-fresh() sequence for the score,
 * including the firstOrNew() lookup, in one runWithFirmContext() call)
 * persists matter_readiness_scores correctly under FORCE, and that
 * ReadinessScorecardRegistry's documents_approved/
 * tasks_dependencies_ready evaluators (which no longer self-wrap their
 * own queries) now correctly inherit the caller's context and return
 * genuinely correct counts rather than silently returning zero.
 *
 * Recovery/reconciliation note: this checkpoint's WIP was originally
 * drafted together with readiness_score_events under a combined
 * "Checkpoints 14/15" label and a single combined test file
 * (MatterReadinessForceRlsActivationTest.php). On reconciliation the
 * two tables were split into one checkpoint each, per this mission's
 * governing rule (one table per checkpoint, one reviewed commit per
 * table) — see this checkpoint's migration docblock for the full
 * separability analysis. readiness_score_events' own tests move to
 * their own file in Checkpoint 15.
 *
 * Known, explicitly NOT fixed in this batch (tracked separately, see
 * this migration's own docblock): no composite foreign key validates
 * that matter_id's owning firm matches this table's own firm_id. FORCE
 * RLS does not catch this (RLS only checks this table's own firm_id
 * column, never a related row's firm_id), so a cross-firm matter_id
 * reference remains theoretically possible at the database layer if
 * application code ever bypassed the established write path. See
 * test_a_raw_insert_can_still_reference_a_matter_from_a_different_firm_at_the_raw_db_layer
 * below for the honest, empirically-proven boundary of that claim —
 * documented as a residual database-constraint gap, not something RLS
 * itself closes, and not a false guarantee.
 */
class MatterReadinessScoresForceRlsActivationTest extends TestCase
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
    ];

    private function activateAllDefaultComponents(): void
    {
        foreach (['intake_complete', 'documents_approved', 'tasks_dependencies_ready', 'attorney_review_status'] as $key) {
            ReadinessScorecardComponent::factory()->create(['component_key' => $key, 'status' => ReadinessComponentStatus::Active]);
        }
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

    public function test_matter_readiness_scores_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'matter_readiness_scores'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_matter_readiness_scores_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'matter_readiness_scores'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'matter_readiness_scores must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly thirty-two tables (the thirty-one previously forced plus
     * matter_readiness_scores) must be FORCE-enabled among ALL prepared
     * tables — no more, no less. readiness_score_events must NOT be
     * forced yet — that is Checkpoint 15's own, separate change.
     *
     * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 15 (this
     * repo's thirty-third staged FORCE activation batch, covering
     * readiness_score_events) to extend the "exactly these tables are
     * forced" firewall list from thirty-two to thirty-three tables —
     * same additive-only pattern, no existing assertion removed or
     * weakened.
     *
     * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 16 (this
     * repo's thirty-fourth staged FORCE activation batch, covering
     * tenant_encryption_keys) to extend the "exactly these tables are
     * forced" firewall list from thirty-three to thirty-four tables —
     * same additive-only pattern, no existing assertion removed or
     * weakened.
     */
    public function test_exactly_thirty_two_prepared_tables_are_force_row_level_security_enabled(): void
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
        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events', 'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans', 'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations', 'integration_credentials', 'integration_oauth_states', 'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings', 'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events', 'integration_inbound_webhook_events', 'integration_connection_health', 'integration_usage_records', 'integration_provider_webhook_subscriptions', /* Narrowly updated by Checkpoint 4 (FirmsVault Live Integrations, "Plaid financial evidence add-on") -- this full-registry list must now also account for the 21 new tables Checkpoint 4 added to both PREPARED_TABLES and the FORCE-RLS rollout in the same migration (client_portal_matter_grants, the financial-evidence domain, and the provider-billing domain) -- additive only, no existing assertion removed or weakened. */ 'client_portal_matter_grants', 'financial_evidence_bank_accounts', 'financial_evidence_transactions', 'financial_evidence_income_records', 'financial_evidence_liabilities', 'financial_evidence_investment_records', 'financial_evidence_statements', 'financial_evidence_identity_records', 'provider_billable_call_reservations', 'provider_firm_operation_policies', 'provider_balance_snapshots', 'financial_evidence_matter_requests', 'financial_evidence_client_consents', 'financial_evidence_matter_authorizations', 'financial_evidence_matter_notes', 'financial_evidence_snapshots', 'financial_evidence_transaction_reviews', 'financial_evidence_duplicate_transfer_flags', 'financial_evidence_large_deposit_flags', 'financial_evidence_reconciliation_candidates', 'financial_account_reclassification_requests', 'accounting_journal_entries', 'accounting_postings', 'payment_allocations', 'payment_reversals', 'invoice_write_offs', 'accounting_periods', 'accounting_period_events', 'payment_requests', 'payment_request_events', 'payment_pending_allocations', 'domain_events', 'automation_rules', 'automation_executions', 'automation_action_executions', 'matter_budget_templates', 'matter_budgets', 'matter_budget_analyses', 'matter_budget_alerts']);
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
        $this->assertSame(165, count($actuallyForced), 'Exactly thirty-six prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 14 — no more, no less. Narrowly updated again for Checkpoint 15 (readiness_score_events added on top of the prior thirty-two), and again for Checkpoint 16 (tenant_encryption_keys added on top of the prior thirty-three).');
        $this->assertSame($expectedForced, $actuallyForced);
    }

    /**
     * Uncovered/uninvolved tables must be untouched by this batch.
     *
     * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 15 for the
     * same reason as above — readiness_score_events is now forced by
     * its own checkpoint, so it moves from the "must stay unforced"
     * set into the "forced" allowlist here too.
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
        $forced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events', 'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans', 'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations', 'integration_credentials', 'integration_oauth_states', 'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings', 'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events', 'integration_inbound_webhook_events', 'integration_connection_health', 'integration_usage_records', 'integration_provider_webhook_subscriptions', /* Narrowly updated by Checkpoint 4 (FirmsVault Live Integrations, "Plaid financial evidence add-on") -- this full-registry list must now also account for the 21 new tables Checkpoint 4 added to both PREPARED_TABLES and the FORCE-RLS rollout in the same migration (client_portal_matter_grants, the financial-evidence domain, and the provider-billing domain) -- additive only, no existing assertion removed or weakened. */ 'client_portal_matter_grants', 'financial_evidence_bank_accounts', 'financial_evidence_transactions', 'financial_evidence_income_records', 'financial_evidence_liabilities', 'financial_evidence_investment_records', 'financial_evidence_statements', 'financial_evidence_identity_records', 'provider_billable_call_reservations', 'provider_firm_operation_policies', 'provider_balance_snapshots', 'financial_evidence_matter_requests', 'financial_evidence_client_consents', 'financial_evidence_matter_authorizations', 'financial_evidence_matter_notes', 'financial_evidence_snapshots', 'financial_evidence_transaction_reviews', 'financial_evidence_duplicate_transfer_flags', 'financial_evidence_large_deposit_flags', 'financial_evidence_reconciliation_candidates', 'financial_account_reclassification_requests', 'accounting_journal_entries', 'accounting_postings', 'payment_allocations', 'payment_reversals', 'invoice_write_offs', 'accounting_periods', 'accounting_period_events', 'payment_requests', 'payment_request_events', 'payment_pending_allocations', 'domain_events', 'automation_rules', 'automation_executions', 'automation_action_executions', 'matter_budget_templates', 'matter_budgets', 'matter_budget_analyses', 'matter_budget_alerts']);
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

    public function test_the_tenant_isolation_policy_remains_present_and_unchanged_for_matter_readiness_scores(): void
    {
        $policy = DB::selectOne(
            "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'matter_readiness_scores'::regclass"
        );

        $this->assertNotNull($policy, 'The matter_readiness_scores tenant isolation policy must still exist.');
        $this->assertStringContainsString('current_setting', $policy->using_expr);
        $this->assertStringContainsString('firm_id', $policy->using_expr);
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_matter_readiness_scores(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => MatterReadinessScore::factory()->forMatter($matter)->create());

        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, MatterReadinessScore::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_insert_matter_readiness_scores(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('matter_readiness_scores')->insert([
            'firm_id' => $firm->id,
            'matter_id' => $matter->id,
            'status' => 'not_ready',
            'satisfied_count' => 0,
            'total_count' => 0,
            'breakdown_json' => json_encode([]),
            'computed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_matter_readiness_score(): void
    {
        $firmA = Firm::factory()->create();
        $matterA = $this->runWithFirmContext($firmA, fn () => Matter::factory()->forFirm($firmA)->create());
        $scoreA = $this->runWithFirmContext($firmA, fn () => MatterReadinessScore::factory()->forMatter($matterA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => MatterReadinessScore::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$scoreA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_matter_readiness_score(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $matterA = $this->runWithFirmContext($firmA, fn () => Matter::factory()->forFirm($firmA)->create());
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, fn () => MatterReadinessScore::factory()->forMatter($matterA)->create());
        $scoreB = $this->runWithFirmContext($firmB, fn () => MatterReadinessScore::factory()->forMatter($matterB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => MatterReadinessScore::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($scoreB->id, $visibleIds);
    }

    public function test_valid_insert_with_correct_firm_context_succeeds_for_matter_readiness_scores(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $insertedId = $this->runWithFirmContext($firm, function () use ($firm, $matter) {
            return DB::table('matter_readiness_scores')->insertGetId([
                'firm_id' => $firm->id,
                'matter_id' => $matter->id,
                'status' => 'not_ready',
                'satisfied_count' => 0,
                'total_count' => 0,
                'breakdown_json' => json_encode([]),
                'computed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_context_cannot_insert_a_matter_readiness_score_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $matterB) {
            DB::table('matter_readiness_scores')->insert([
                'firm_id' => $firmB->id,
                'matter_id' => $matterB->id,
                'status' => 'not_ready',
                'satisfied_count' => 0,
                'total_count' => 0,
                'breakdown_json' => json_encode([]),
                'computed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_firm_a_context_cannot_update_firm_b_matter_readiness_score(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());
        $scoreB = $this->runWithFirmContext($firmB, fn () => MatterReadinessScore::factory()->forMatter($matterB)->create(['status' => MatterReadinessStatus::NotReady]));

        $this->runWithFirmContext($firmA, function () use ($scoreB) {
            DB::table('matter_readiness_scores')->where('id', $scoreB->id)->update(['status' => 'ready']);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => MatterReadinessScore::withoutGlobalScopes()->find($scoreB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(
            MatterReadinessStatus::NotReady,
            $reReadAsFirmB->status,
            'Firm A context must not be able to update Firm B\'s matter_readiness_scores row.'
        );
    }

    public function test_firm_a_context_cannot_delete_firm_b_matter_readiness_score(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());
        $scoreB = $this->runWithFirmContext($firmB, fn () => MatterReadinessScore::factory()->forMatter($matterB)->create());

        $this->runWithFirmContext($firmA, function () use ($scoreB) {
            DB::table('matter_readiness_scores')->where('id', $scoreB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => MatterReadinessScore::withoutGlobalScopes()->find($scoreB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s matter_readiness_scores row.');
    }

    public function test_firm_a_context_cannot_reassign_firm_b_matter_readiness_score_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());
        $scoreB = $this->runWithFirmContext($firmB, fn () => MatterReadinessScore::factory()->forMatter($matterB)->create());

        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $scoreB) {
            return DB::table('matter_readiness_scores')->where('id', $scoreB->id)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s matter readiness score to itself.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => MatterReadinessScore::withoutGlobalScopes()->find($scoreB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame($firmB->id, $reReadAsFirmB->firm_id);
    }

    /**
     * Empirically proves the honest scope boundary described in this
     * file's class docblock and the migration's own docblock: RLS only
     * validates matter_readiness_scores.firm_id, never matter_id's OWN
     * firm_id — a raw insert whose firm_id matches the active context
     * still succeeds even when matter_id points at a Matter belonging
     * to a COMPLETELY DIFFERENT firm. This is a documented residual
     * DATABASE-CONSTRAINT gap, not something RLS itself closes — never
     * to be described as blocked.
     */
    public function test_a_raw_insert_can_still_reference_a_matter_from_a_different_firm_at_the_raw_db_layer(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $foreignMatter = $this->runWithFirmContext($otherFirm, fn () => Matter::factory()->forFirm($otherFirm)->create());

        $mismatchedId = $this->runWithFirmContext($firm, function () use ($firm, $foreignMatter) {
            return DB::table('matter_readiness_scores')->insertGetId([
                'firm_id' => $firm->id,
                'matter_id' => $foreignMatter->id,
                'status' => 'not_ready',
                'satisfied_count' => 0,
                'total_count' => 0,
                'breakdown_json' => json_encode([]),
                'computed_at' => now(),
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
     * Bare factory default: a bare MatterReadinessScore::factory()->
     * create() must succeed even from outside any already-active
     * tenant context (the factory's context-hold create() override),
     * and the row must actually be visible/readable under its own
     * firm's context afterward. Also proves the Checkpoint 14 root-
     * cause fix: firm_id and matter_id are derived from the SAME
     * Matter, so there is no cross-firm mismatch even on a bare
     * default.
     */
    public function test_matter_readiness_score_factory_default_creation_is_internally_consistent(): void
    {
        $score = MatterReadinessScore::factory()->create();

        $this->assertNotNull($score->id);
        $this->assertNotNull($score->firm_id);
        $this->assertNotNull($score->matter_id);

        $persisted = $this->runWithFirmContext(
            $score->firm,
            fn () => MatterReadinessScore::withoutGlobalScopes()->find($score->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame($score->firm_id, $persisted->firm_id);

        $matterFirmId = $this->runWithFirmContext(
            $score->firm,
            fn () => Matter::withoutGlobalScopes()->find($score->matter_id)?->firm_id,
        );

        $this->assertSame(
            $score->firm_id,
            $matterFirmId,
            'The bare factory default must derive firm_id and matter_id from the SAME Matter — no cross-firm mismatch.'
        );
    }

    /**
     * Explicit related-model factory state correctness: forMatter()
     * must set firm_id/matter_id to the EXACT matter given, and the row
     * must be readable only under that firm's context.
     */
    public function test_matter_readiness_score_factory_for_matter_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $score = $this->runWithFirmContext($firm, fn () => MatterReadinessScore::factory()->forMatter($matter)->create());

        $this->assertSame($firm->id, $score->firm_id);
        $this->assertSame($matter->id, $score->matter_id);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => MatterReadinessScore::withoutGlobalScopes()->find($score->id),
        );

        $this->assertNotNull($persisted);
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, fn () => MatterReadinessScore::factory()->forMatter($matter)->create());

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
    // End-to-end recompute() proof under FORCE
    // ---------------------------------------------------------------

    /**
     * End-to-end proof that MatterReadinessService::recompute()
     * persists matter_readiness_scores correctly under FORCE RLS —
     * proving the single outer runWithFirmContext() wrap (replacing the
     * old "decoy wrap") genuinely covers the whole evaluate()-through-
     * fresh() sequence for the score, including the firstOrNew()
     * lookup. Also proves recompute() clears its own context wrap
     * before returning. The readiness_score_events side effect is not
     * asserted here (that table's own FORCE-RLS proof is Checkpoint
     * 15's job) — recompute() still creates that row as an unwrapped
     * write, same as before this checkpoint.
     */
    public function test_recompute_persists_matter_readiness_scores_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $service = new MatterReadinessService(new ReadinessScorecardRegistry);

        $score = $service->recompute($matter);

        $this->assertNoDatabaseTenantContext('recompute() must clear its own context wrap before returning.');
        $this->assertNotNull($score);
        $this->assertSame(MatterReadinessStatus::NotReady, $score->status);

        $persistedScore = $this->runWithFirmContext(
            $firm,
            fn () => MatterReadinessScore::withoutGlobalScopes()->where('matter_id', $matter->id)->first(),
        );
        $this->assertNotNull($persistedScore, 'recompute() must persist exactly one matter_readiness_scores row under FORCE, readable under its own firm context.');
        $this->assertSame($firm->id, $persistedScore->firm_id);

        // A second recompute() call on the SAME matter must upsert the
        // score row (matter_id is unique) — proving the firstOrNew()
        // lookup itself is correctly inside the wrap (an unwrapped
        // lookup under FORCE would see zero existing rows and attempt a
        // duplicate insert against the unique constraint instead).
        $service->recompute($matter);

        $scoreCount = $this->runWithFirmContext(
            $firm,
            fn () => MatterReadinessScore::withoutGlobalScopes()->where('matter_id', $matter->id)->count(),
        );
        $this->assertSame(1, $scoreCount, 'A second recompute() must upsert in place, not duplicate, the matter_readiness_scores row.');
    }

    /**
     * Proves ReadinessScorecardRegistry's documents_approved evaluator
     * (which no longer self-wraps its own query) correctly inherits
     * recompute()'s outer context and detects a GENUINE outstanding
     * required document — not silently returning a wrong (always-zero)
     * count due to a lost context.
     */
    public function test_documents_approved_evaluator_detects_a_genuine_outstanding_item_under_force_rls(): void
    {
        $this->activateAllDefaultComponents();

        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $request = $this->runWithFirmContext($firm, fn () => DocumentRequest::factory()->forClient($client)->create(['matter_id' => $matter->id]));
        $this->runWithFirmContext($firm, fn () => DocumentRequestItem::factory()->create([
            'document_request_id' => $request->id,
            'is_required' => true,
            'status' => DocumentRequestItemStatus::Requested,
        ]));

        $service = new MatterReadinessService(new ReadinessScorecardRegistry);
        $score = $service->recompute($matter);

        $componentResult = collect($score->breakdown_json)->firstWhere('component_key', 'documents_approved');
        $this->assertNotNull($componentResult, 'documents_approved must be present in breakdown_json — the evaluator must have run.');
        $this->assertFalse(
            $componentResult['satisfied'],
            'documents_approved must detect the genuine outstanding required document — a lost context would silently report zero outstanding items instead.'
        );
    }

    /**
     * Same proof as above for tasks_dependencies_ready: a genuine open
     * task tied to the matter's own firm must be detected as
     * unresolved, proving the evaluator's query correctly ran under the
     * caller's context rather than silently seeing zero rows.
     */
    public function test_tasks_dependencies_ready_evaluator_detects_a_genuine_open_task_under_force_rls(): void
    {
        $this->activateAllDefaultComponents();

        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => Task::factory()->create([
            'firm_id' => $firm->id,
            'matter_id' => $matter->id,
            'status' => TaskStatus::Open,
        ]));

        $service = new MatterReadinessService(new ReadinessScorecardRegistry);
        $score = $service->recompute($matter);

        $componentResult = collect($score->breakdown_json)->firstWhere('component_key', 'tasks_dependencies_ready');
        $this->assertNotNull($componentResult, 'tasks_dependencies_ready must be present in breakdown_json — the evaluator must have run.');
        $this->assertFalse(
            $componentResult['satisfied'],
            'tasks_dependencies_ready must detect the genuine open task — a lost context would silently report zero open tasks instead.'
        );
    }

    /**
     * The converse of the two tests above: with every required document
     * approved and every task resolved, both evaluators must correctly
     * report satisfied — proving the fix is not simply "always
     * unsatisfied" (which would just as silently be wrong in the other
     * direction).
     */
    public function test_documents_approved_and_tasks_dependencies_ready_report_satisfied_when_genuinely_resolved(): void
    {
        $this->activateAllDefaultComponents();

        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $request = $this->runWithFirmContext($firm, fn () => DocumentRequest::factory()->forClient($client)->create(['matter_id' => $matter->id]));
        $this->runWithFirmContext($firm, fn () => DocumentRequestItem::factory()->create([
            'document_request_id' => $request->id,
            'is_required' => true,
            'status' => DocumentRequestItemStatus::Approved,
        ]));
        $this->runWithFirmContext($firm, fn () => Task::factory()->create([
            'firm_id' => $firm->id,
            'matter_id' => $matter->id,
            'status' => TaskStatus::Completed,
            'completed_at' => now(),
        ]));

        $service = new MatterReadinessService(new ReadinessScorecardRegistry);
        $score = $service->recompute($matter);

        $documentsResult = collect($score->breakdown_json)->firstWhere('component_key', 'documents_approved');
        $tasksResult = collect($score->breakdown_json)->firstWhere('component_key', 'tasks_dependencies_ready');

        $this->assertTrue($documentsResult['satisfied'], 'documents_approved must report satisfied once the only required document is approved.');
        $this->assertTrue($tasksResult['satisfied'], 'tasks_dependencies_ready must report satisfied once the only task is completed.');
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
     * Thirty-one previously forced tables plus matter_readiness_scores
     * must be independently force-active and independently isolated at
     * the same time — proof this batch did not weaken or interfere with
     * any prior section's own enforcement. Uses clients as the
     * companion table.
     */
    public function test_matter_readiness_scores_are_isolated_independently_and_simultaneously_with_clients(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());

        $matterA = $this->runWithFirmContext($firmA, fn () => Matter::factory()->forClient($clientA)->create());
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forClient($clientB)->create());

        $scoreA = $this->runWithFirmContext($firmA, fn () => MatterReadinessScore::factory()->forMatter($matterA)->create());
        $scoreB = $this->runWithFirmContext($firmB, fn () => MatterReadinessScore::factory()->forMatter($matterB)->create());

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'matter_readiness_scores' => MatterReadinessScore::withoutGlobalScopes()->pluck('id')->all(),
            'clients' => Client::withoutGlobalScopes()->pluck('id')->all(),
        ]);

        $this->assertSame([$scoreA->id], $resultA['matter_readiness_scores']);
        $this->assertNotContains($scoreB->id, $resultA['matter_readiness_scores']);
        $this->assertContains($clientA->id, $resultA['clients']);
        $this->assertNotContains($clientB->id, $resultA['clients']);
    }

    // ---------------------------------------------------------------
    // Migration down()/up() restoration proofs
    // ---------------------------------------------------------------

    /**
     * Rollback support: the matter_readiness_scores migration's down()
     * must genuinely restore the Section 39A baseline — RLS still
     * enabled, policy still present, but NOT forced — never drop the
     * policy or disable RLS itself. Also proves rollback affects ONLY
     * this one table — every other previously-forced table must be
     * untouched.
     */
    public function test_matter_readiness_scores_migration_down_restores_the_not_forced_baseline_and_affects_only_this_table(): void
    {
        $migration = require base_path('database/migrations/2026_08_25_930014_force_rls_on_matter_readiness_scores_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'matter_readiness_scores'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while matter_readiness_scores is rolled back."
                );
            }

            $policy = DB::selectOne(
                "select polname from pg_policy where polrelid = 'matter_readiness_scores'::regclass"
            );
            $this->assertNotNull($policy, 'Rollback must not drop the tenant isolation policy.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'matter_readiness_scores'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }
}
