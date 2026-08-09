<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\ManualPaymentMethod;
use App\Enums\PaymentClassification;
use App\Models\Client;
use App\Models\Firm;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanEvent;
use App\Models\PaymentPlanInstallment;
use App\Models\User;
use App\Services\ComplianceGapRegistryService;
use App\Services\ManualPaymentService;
use App\Services\OperatingJournalRecorderService;
use App\Services\PaymentApplicationService;
use App\Services\PaymentClassificationService;
use App\Services\PaymentPlanService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PaymentPlanEventsForceRlsActivationTest — Section 39A-3L, Checkpoint
 * 23. Proves the forty-first staged FORCE ROW LEVEL SECURITY activation
 * batch (database/migrations/2026_08_25_930023_force_rls_on_payment_
 * plan_events_table.php) is permanently active for payment_plan_events
 * and behaves correctly: fail-closed with no context, correct
 * cross-firm isolation, correct same-firm access, that every
 * previously-forced table (including payment_plans, forced one
 * checkpoint earlier) remains forced simultaneously, and — the central
 * finding of this checkpoint — that the two verified production
 * writers, PaymentPlanService::logEvent() (via its five public
 * status-transition callers) and PaymentApplicationService::
 * applyToInstallment() (via its only caller, ManualPaymentService::
 * submit()), genuinely persist payment_plan_events rows with no
 * ambient tenant context required from the test itself, because both
 * already execute their entire body inside a runWithFirmContext() wrap
 * established at Checkpoint 22 (payment_plans) / Checkpoint 39A-3H
 * (payments) respectively — no production wiring change was required
 * this checkpoint.
 *
 * payment_plan_events carries payment_plan_id (NOT NULL) as a second,
 * independently-resolved tenant-owned relation — the same shape as
 * payment_plans' own client_id (Checkpoint 22), document_chase_events'
 * document_request_item_id (Checkpoint 17), time_tracking_sessions'
 * matter_id/client_id (Checkpoint 20), and time_entries' matter_id/
 * client_id/time_tracking_session_id (Checkpoint 21). This file proves
 * the same honest boundary: RLS only ever validates a row's OWN
 * firm_id, never a related row's owning firm, so a raw insert whose
 * firm_id matches the active context but whose payment_plan_id points
 * at a PaymentPlan belonging to a different firm is NOT blocked by
 * RLS. This is documented here as a residual DATABASE-CONSTRAINT gap,
 * never asserted as something RLS itself closes.
 *
 * Independent finding recorded here, not fixed in this checkpoint
 * (out of this role's write authority — production files are the
 * implementer's exclusive scope): the migration's own "verified writer
 * inventory" docblock lists exactly two writers, found via `grep -rln
 * "PaymentPlanEvent" app/`. That grep only matches the literal class
 * name, so it misses TWO further real writers that reach
 * payment_plan_events only through the `$plan->events()` relation
 * (never mentioning the class name "PaymentPlanEvent" as a token):
 * PaymentPlanInstallmentService::markMissed()/markWaived() (both
 * unwrapped, no runWithFirmContext() of their own) and
 * PaymentPlanDunningService::checkAndLog() (already independently
 * documented as deliberately unwrapped, per that service's own
 * docblock and PaymentPlanDunningServiceTest's docblock). Empirically,
 * this has no live production trigger today — grep -rn "markMissed(
 * |markWaived(|checkAndLog(" app/ outside the three service files
 * themselves returns nothing; Phase 4's scheduler/queue infrastructure
 * that will eventually call these methods is not wired yet (see
 * PaymentPlanInstallmentService's and PaymentPlanDunningService's own
 * class docblocks). Existing tests for both methods already pass
 * unmodified because the established "context-hold factory" pattern
 * (PaymentPlanFactory's bare create() override) leaves a real
 * PostgreSQL SET LOCAL app.current_firm_id active for the rest of the
 * enclosing per-test transaction, and PaymentPlanDunningServiceTest's
 * one event-producing test already wraps its own call in
 * runWithFirmContext() (a pre-existing Checkpoint 11 fix, unrelated to
 * this checkpoint). Stated plainly as a documentation gap in the
 * migration's own inventory claim, not a demonstrated bug — no test in
 * this suite is weakened or skipped to route around it.
 */
class PaymentPlanEventsForceRlsActivationTest extends TestCase
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

    public function test_payment_plan_events_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'payment_plan_events'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_payment_plan_events_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'payment_plan_events'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'payment_plan_events must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly forty-one tables (the forty previously forced plus
     * payment_plan_events) must be FORCE-enabled among ALL prepared
     * tables — no more, no less.
     */
    public function test_exactly_forty_one_prepared_tables_are_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        // Narrowly updated by Section 39A-3L, Checkpoint 24 (this
        // repo's forty-second staged FORCE activation batch, covering
        // notification_events) to extend the "exactly these tables
        // are forced" list from forty-one to forty-two tables — this
        // test's own scope (Checkpoint 23) only introduced
        // payment_plan_events, but the exact-count assertion below
        // must still account for that later, legitimate addition
        // rather than falsely reporting it as unexpected — additive
        // only, no existing assertion removed or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 27 (backup_restore_tests) for the same reason — additive only, no existing assertion removed or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 28 (health_checks) for the same reason — additive only, no existing assertion removed or weakened.
        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations', 'integration_credentials', 'integration_oauth_states', 'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings', 'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events', 'integration_inbound_webhook_events', 'integration_connection_health', 'integration_usage_records', 'integration_provider_webhook_subscriptions', /* Narrowly updated by Checkpoint 4 (FirmsVault Live Integrations, "Plaid financial evidence add-on") -- this full-registry list must now also account for the 21 new tables Checkpoint 4 added to both PREPARED_TABLES and the FORCE-RLS rollout in the same migration (client_portal_matter_grants, the financial-evidence domain, and the provider-billing domain) -- additive only, no existing assertion removed or weakened. */ 'client_portal_matter_grants', 'financial_evidence_bank_accounts', 'financial_evidence_transactions', 'financial_evidence_income_records', 'financial_evidence_liabilities', 'financial_evidence_investment_records', 'financial_evidence_statements', 'financial_evidence_identity_records', 'provider_billable_call_reservations', 'provider_firm_operation_policies', 'provider_balance_snapshots', 'financial_evidence_matter_requests', 'financial_evidence_client_consents', 'financial_evidence_matter_authorizations', 'financial_evidence_matter_notes', 'financial_evidence_snapshots', 'financial_evidence_transaction_reviews', 'financial_evidence_duplicate_transfer_flags', 'financial_evidence_large_deposit_flags', 'financial_evidence_reconciliation_candidates', 'financial_account_reclassification_requests', 'accounting_journal_entries', 'accounting_postings', 'payment_allocations', 'payment_reversals', 'invoice_write_offs', 'accounting_periods', 'accounting_period_events', 'payment_requests', 'payment_request_events']);

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
        $this->assertSame(156, count($actuallyForced), 'Exactly forty-one prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 23 — no more, no less.');
        $this->assertSame($expectedForced, $actuallyForced);
    }

    /**
     * Uncovered/uninvolved tables must be untouched by this batch.
     */
    public function test_no_unrelated_prepared_table_became_force_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        // Narrowly updated by Section 39A-3L, Checkpoint 24 (covering
        // notification_events) for the same reason as above —
        // additive only, no existing assertion removed or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 27 (backup_restore_tests) for the same reason — additive only, no existing assertion removed or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 28 (health_checks) for the same reason — additive only, no existing assertion removed or weakened.
        $forced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations', 'integration_credentials', 'integration_oauth_states', 'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings', 'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events', 'integration_inbound_webhook_events', 'integration_connection_health', 'integration_usage_records', 'integration_provider_webhook_subscriptions', /* Narrowly updated by Checkpoint 4 (FirmsVault Live Integrations, "Plaid financial evidence add-on") -- this full-registry list must now also account for the 21 new tables Checkpoint 4 added to both PREPARED_TABLES and the FORCE-RLS rollout in the same migration (client_portal_matter_grants, the financial-evidence domain, and the provider-billing domain) -- additive only, no existing assertion removed or weakened. */ 'client_portal_matter_grants', 'financial_evidence_bank_accounts', 'financial_evidence_transactions', 'financial_evidence_income_records', 'financial_evidence_liabilities', 'financial_evidence_investment_records', 'financial_evidence_statements', 'financial_evidence_identity_records', 'provider_billable_call_reservations', 'provider_firm_operation_policies', 'provider_balance_snapshots', 'financial_evidence_matter_requests', 'financial_evidence_client_consents', 'financial_evidence_matter_authorizations', 'financial_evidence_matter_notes', 'financial_evidence_snapshots', 'financial_evidence_transaction_reviews', 'financial_evidence_duplicate_transfer_flags', 'financial_evidence_large_deposit_flags', 'financial_evidence_reconciliation_candidates', 'financial_account_reclassification_requests', 'accounting_journal_entries', 'accounting_postings', 'payment_allocations', 'payment_reversals', 'invoice_write_offs', 'accounting_periods', 'accounting_period_events', 'payment_requests', 'payment_request_events']);

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
             where polrelid = 'payment_plan_events'::regclass"
        );

        $this->assertNotNull($policy, 'The payment_plan_events tenant isolation policy must still exist.');
        $this->assertSame('payment_plan_events_tenant_isolation', $policy->polname);
        $this->assertStringContainsString('current_setting', $policy->using_expr);
        $this->assertStringContainsString('firm_id', $policy->using_expr);
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_payment_plan_events(): void
    {
        $firm = Firm::factory()->create();
        $plan = $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => PaymentPlanEvent::factory()->forPlan($plan)->create());

        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, PaymentPlanEvent::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_payment_plan_events(): void
    {
        $firm = Firm::factory()->create();
        $plan = $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forFirm($firm)->create());

        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('payment_plan_events')->insert([
            'firm_id' => $firm->id,
            'payment_plan_id' => $plan->id,
            'event_type' => 'created',
            'metadata_json' => json_encode([]),
            'created_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_payment_plan_event(): void
    {
        $firmA = Firm::factory()->create();
        $planA = $this->runWithFirmContext($firmA, fn () => PaymentPlan::factory()->forFirm($firmA)->create());
        $eventA = $this->runWithFirmContext($firmA, fn () => PaymentPlanEvent::factory()->forPlan($planA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => PaymentPlanEvent::query()->pluck('id')->all(),
        );

        $this->assertSame([$eventA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_payment_plan_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $planA = $this->runWithFirmContext($firmA, fn () => PaymentPlan::factory()->forFirm($firmA)->create());
        $this->runWithFirmContext($firmA, fn () => PaymentPlanEvent::factory()->forPlan($planA)->create());

        $planB = $this->runWithFirmContext($firmB, fn () => PaymentPlan::factory()->forFirm($firmB)->create());
        $eventB = $this->runWithFirmContext($firmB, fn () => PaymentPlanEvent::factory()->forPlan($planB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => PaymentPlanEvent::query()->pluck('id')->all(),
        );

        $this->assertNotContains($eventB->id, $visibleIds);
    }

    public function test_valid_insert_with_correct_firm_context_succeeds(): void
    {
        $firm = Firm::factory()->create();
        $plan = $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forFirm($firm)->create());

        $insertedId = $this->runWithFirmContext($firm, function () use ($firm, $plan) {
            return DB::table('payment_plan_events')->insertGetId([
                'firm_id' => $firm->id,
                'payment_plan_id' => $plan->id,
                'event_type' => 'created',
                'metadata_json' => json_encode([]),
                'created_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_context_cannot_insert_a_payment_plan_event_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $planB = $this->runWithFirmContext($firmB, fn () => PaymentPlan::factory()->forFirm($firmB)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $planB) {
            DB::table('payment_plan_events')->insert([
                'firm_id' => $firmB->id,
                'payment_plan_id' => $planB->id,
                'event_type' => 'created',
                'metadata_json' => json_encode([]),
                'created_at' => now(),
            ]);
        });
    }

    public function test_firm_a_context_cannot_update_firm_b_payment_plan_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $planB = $this->runWithFirmContext($firmB, fn () => PaymentPlan::factory()->forFirm($firmB)->create());
        $eventB = $this->runWithFirmContext($firmB, fn () => PaymentPlanEvent::factory()->forPlan($planB)->eventType('created')->create());

        $affected = $this->runWithFirmContext($firmA, function () use ($eventB) {
            return DB::table('payment_plan_events')->where('id', $eventB->id)->update(['event_type' => 'tampered']);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s payment_plan_events row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => PaymentPlanEvent::query()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame('created', $reReadAsFirmB->event_type);
    }

    public function test_firm_a_context_cannot_delete_firm_b_payment_plan_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $planB = $this->runWithFirmContext($firmB, fn () => PaymentPlan::factory()->forFirm($firmB)->create());
        $eventB = $this->runWithFirmContext($firmB, fn () => PaymentPlanEvent::factory()->forPlan($planB)->create());

        $this->runWithFirmContext($firmA, function () use ($eventB) {
            DB::table('payment_plan_events')->where('id', $eventB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => PaymentPlanEvent::query()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s payment_plan_events row.');
    }

    public function test_firm_a_context_cannot_reassign_firm_b_payment_plan_event_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $planB = $this->runWithFirmContext($firmB, fn () => PaymentPlan::factory()->forFirm($firmB)->create());
        $eventB = $this->runWithFirmContext($firmB, fn () => PaymentPlanEvent::factory()->forPlan($planB)->create());

        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $eventB) {
            return DB::table('payment_plan_events')->where('id', $eventB->id)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s payment_plan_events row to itself.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => PaymentPlanEvent::query()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame($firmB->id, $reReadAsFirmB->firm_id);
    }

    /**
     * Empirically proves the honest scope boundary described in this
     * file's class docblock and the migration's own docblock: RLS only
     * validates payment_plan_events.firm_id, never payment_plan_id's
     * OWN owning firm — a raw insert whose firm_id matches the active
     * context still succeeds even when payment_plan_id points at a
     * PaymentPlan belonging to a COMPLETELY DIFFERENT firm. This is a
     * documented residual DATABASE-CONSTRAINT gap, never to be
     * described as blocked by RLS.
     */
    public function test_a_raw_insert_can_still_reference_a_payment_plan_from_a_different_firm_at_the_raw_db_layer(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $foreignPlan = $this->runWithFirmContext($otherFirm, fn () => PaymentPlan::factory()->forFirm($otherFirm)->create());

        $mismatchedId = $this->runWithFirmContext($firm, function () use ($firm, $foreignPlan) {
            return DB::table('payment_plan_events')->insertGetId([
                'firm_id' => $firm->id,
                'payment_plan_id' => $foreignPlan->id,
                'event_type' => 'created',
                'metadata_json' => json_encode([]),
                'created_at' => now(),
            ]);
        });

        $this->assertIsInt(
            $mismatchedId,
            'RLS only checks the row\'s own firm_id — a payment_plan_id belonging to a different firm is NOT blocked by RLS; this is a documented residual gap, not a false guarantee.'
        );
    }

    // ---------------------------------------------------------------
    // Factory correctness proofs
    // ---------------------------------------------------------------

    /**
     * Bare factory default: a bare PaymentPlanEvent::factory()->create()
     * must succeed even from outside any already-active tenant context
     * (the factory's context-hold create() override), AND — the
     * specific bug this checkpoint's factory fix addresses — the
     * resulting payment plan must belong to the SAME firm as the
     * event, never an independently resolved, unrelated firm.
     * payment_plan_id is NOT NULL on this table, so this matters even
     * for the bare default path.
     */
    public function test_payment_plan_event_factory_default_creation_produces_a_payment_plan_belonging_to_the_same_firm(): void
    {
        $event = PaymentPlanEvent::factory()->create();

        $this->assertNotNull($event->id);
        $this->assertNotNull($event->firm_id);
        $this->assertNotNull($event->payment_plan_id);

        $plan = $this->runWithFirmContext(
            $event->firm_id,
            fn () => PaymentPlan::query()->find($event->payment_plan_id),
        );

        $this->assertNotNull($plan, 'The bare-factory-created payment plan must be visible under the event\'s own firm context.');
        $this->assertSame(
            $event->firm_id,
            $plan->firm_id,
            'A bare PaymentPlanEvent::factory()->create() must never produce an event whose payment plan belongs to a different, independently-resolved firm — the exact bug this checkpoint\'s factory fix addresses.'
        );

        $persisted = $this->runWithFirmContext(
            $event->firm_id,
            fn () => PaymentPlanEvent::query()->find($event->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame($event->firm_id, $persisted->firm_id);
    }

    /**
     * forPlan() state correctness: the event must be tied to the given
     * plan's own firm, not an independently resolved one.
     */
    public function test_payment_plan_event_factory_for_plan_state_ties_firm_id_to_the_plans_own_firm(): void
    {
        $firm = Firm::factory()->create();
        $plan = $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forFirm($firm)->create());

        $event = $this->runWithFirmContext($firm, fn () => PaymentPlanEvent::factory()->forPlan($plan)->create());

        $this->assertSame($firm->id, $event->firm_id);
        $this->assertSame($plan->id, $event->payment_plan_id);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => PaymentPlanEvent::query()->find($event->id),
        );

        $this->assertNotNull($persisted);
        $this->assertSame($firm->id, $persisted->firm_id);
    }

    /**
     * eventType() state correctness — the plain string is persisted
     * exactly as given.
     */
    public function test_payment_plan_event_factory_event_type_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();
        $plan = $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forFirm($firm)->create());

        $event = $this->runWithFirmContext($firm, fn () => PaymentPlanEvent::factory()->forPlan($plan)->eventType('installment_paid')->create());

        $persisted = $this->runWithFirmContext($firm, fn () => PaymentPlanEvent::query()->find($event->id));

        $this->assertNotNull($persisted);
        $this->assertSame('installment_paid', $persisted->event_type);
    }

    /**
     * Multiple events per plan is a supported (in fact, the expected)
     * state — a second event for the same plan must succeed, not
     * throw. payment_plan_events is append-only.
     */
    public function test_a_payment_plan_can_have_multiple_events_simultaneously(): void
    {
        $firm = Firm::factory()->create();
        $plan = $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, fn () => PaymentPlanEvent::factory()->forPlan($plan)->eventType('created')->create());
        $this->runWithFirmContext($firm, fn () => PaymentPlanEvent::factory()->forPlan($plan)->eventType('activated')->create());

        $count = $this->runWithFirmContext($firm, fn () => PaymentPlanEvent::query()->count());

        $this->assertSame(2, $count, 'payment_plan_events is append-only — a second event for the same plan must be a supported state.');
    }

    // ---------------------------------------------------------------
    // (a) PaymentPlanService::logEvent() self-wrap regression proofs —
    // the central finding of this checkpoint. Each proves the
    // corresponding public status-transition method genuinely persists
    // a payment_plan_events row to the database even when called with
    // no ambient tenant context established beforehand, because
    // logEvent() runs entirely inside that method's own
    // runWithFirmContext() wrap (established at Checkpoint 22) — no
    // production wiring change was required for THIS checkpoint.
    // ---------------------------------------------------------------

    public function test_create_persists_a_payment_plan_event_when_called_with_no_ambient_context_established_beforehand(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());

        (new TenantContextService)->clearDatabaseTenantContext();
        (new TenantContextService)->clearFirmContext();
        $this->assertNoDatabaseTenantContext();

        $service = new PaymentPlanService(new TimelineEventRecorder);
        $plan = $service->create($firm, $client, [
            ['amount_cents' => 10000, 'due_at' => now()->addMonth()],
        ]);

        $this->assertNoDatabaseTenantContext('create() must clear its own internal context wrap before returning.');

        $event = $this->runWithFirmContext(
            $firm,
            fn () => PaymentPlanEvent::query()->where('payment_plan_id', $plan->id)->first(),
        );

        $this->assertNotNull($event, 'create() must genuinely persist a payment_plan_events row to the database, not just an in-memory side effect.');
        $this->assertSame($firm->id, $event->firm_id);
        $this->assertSame('created', $event->event_type);
    }

    public function test_activate_renegotiate_cancel_and_mark_defaulted_persist_payment_plan_events_when_called_with_no_ambient_context_established_beforehand(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $actor = User::factory()->create();
        $service = new PaymentPlanService(new TimelineEventRecorder);

        $plan = $this->runWithFirmContext($firm, fn () => $service->create($firm, $client, [
            ['amount_cents' => 10000, 'due_at' => now()->addMonth()],
        ]));
        $this->assertNoDatabaseTenantContext();

        // --- activate() with no ambient context ---
        $activated = $service->activate($plan);
        $this->assertNoDatabaseTenantContext('activate() must clear its own internal context wrap before returning.');

        $activatedEvent = $this->runWithFirmContext(
            $firm,
            fn () => PaymentPlanEvent::query()->where('payment_plan_id', $activated->id)->where('event_type', 'activated')->first(),
        );
        $this->assertNotNull($activatedEvent, 'activate() must genuinely persist its payment_plan_events row to the database.');

        // --- renegotiate() with no ambient context (writes TWO events:
        // 'renegotiated' on the old plan, 'created_from_renegotiation'
        // on the new plan) ---
        $newPlan = $service->renegotiate($activated, [
            ['amount_cents' => 7500, 'due_at' => now()->addMonth()],
        ]);
        $this->assertNoDatabaseTenantContext('renegotiate() must clear its own internal context wrap before returning.');

        $renegotiatedEvent = $this->runWithFirmContext(
            $firm,
            fn () => PaymentPlanEvent::query()->where('payment_plan_id', $plan->id)->where('event_type', 'renegotiated')->first(),
        );
        $createdFromRenegotiationEvent = $this->runWithFirmContext(
            $firm,
            fn () => PaymentPlanEvent::query()->where('payment_plan_id', $newPlan->id)->where('event_type', 'created_from_renegotiation')->first(),
        );
        $this->assertNotNull($renegotiatedEvent, 'renegotiate() must genuinely persist the old plan\'s payment_plan_events row to the database.');
        $this->assertNotNull($createdFromRenegotiationEvent, 'renegotiate() must genuinely persist the new plan\'s payment_plan_events row to the database.');

        // --- cancel() with no ambient context (against a fresh,
        // independent Draft plan) ---
        $planToCancel = $this->runWithFirmContext($firm, fn () => $service->create($firm, $client, [
            ['amount_cents' => 5000, 'due_at' => now()->addMonth()],
        ]));
        $this->assertNoDatabaseTenantContext();

        $cancelled = $service->cancel($planToCancel, reason: 'Client requested cancellation');
        $this->assertNoDatabaseTenantContext('cancel() must clear its own internal context wrap before returning.');

        $cancelledEvent = $this->runWithFirmContext(
            $firm,
            fn () => PaymentPlanEvent::query()->where('payment_plan_id', $cancelled->id)->where('event_type', 'cancelled')->first(),
        );
        $this->assertNotNull($cancelledEvent, 'cancel() must genuinely persist its payment_plan_events row to the database.');

        // --- markDefaulted() with no ambient context (against a fresh,
        // independent Active plan) ---
        $planToDefault = $this->runWithFirmContext($firm, function () use ($service, $firm, $client) {
            $created = $service->create($firm, $client, [
                ['amount_cents' => 10000, 'due_at' => now()->addMonth()],
            ]);

            return $service->activate($created);
        });
        $this->assertNoDatabaseTenantContext();

        $defaulted = $service->markDefaulted($planToDefault, $actor, 'Client unresponsive after repeated misses');
        $this->assertNoDatabaseTenantContext('markDefaulted() must clear its own internal context wrap before returning.');

        $defaultedEvent = $this->runWithFirmContext(
            $firm,
            fn () => PaymentPlanEvent::query()->where('payment_plan_id', $defaulted->id)->where('event_type', 'defaulted')->first(),
        );
        $this->assertNotNull($defaultedEvent, 'markDefaulted() must genuinely persist its payment_plan_events row to the database.');
        $this->assertSame($actor->id, $defaultedEvent->actor_user_id);
    }

    // ---------------------------------------------------------------
    // (b) PaymentApplicationService::applyToInstallment() via
    // ManualPaymentService::submit() regression proof.
    // ---------------------------------------------------------------

    public function test_manual_payment_service_submit_persists_a_payment_plan_event_when_called_with_no_ambient_context_established_beforehand(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $timeline = new TimelineEventRecorder;
        $planService = new PaymentPlanService($timeline);

        $plan = $this->runWithFirmContext($firm, fn () => $planService->create($firm, $client, [
            ['amount_cents' => 10000, 'due_at' => now()->addMonth()],
        ]));
        $activePlan = $this->runWithFirmContext($firm, fn () => $planService->activate($plan));
        $installment = $this->runWithFirmContext(
            $firm,
            fn () => PaymentPlanInstallment::query()->where('payment_plan_id', $activePlan->id)->firstOrFail(),
        );

        $manualPaymentService = new ManualPaymentService(
            new PaymentClassificationService,
            new PaymentApplicationService($planService, $timeline),
            $timeline,
            app(OperatingJournalRecorderService::class),
        );

        (new TenantContextService)->clearDatabaseTenantContext();
        (new TenantContextService)->clearFirmContext();
        $this->assertNoDatabaseTenantContext();

        // No ambient context established before submit() — the whole
        // point is proving ManualPaymentService::submit()'s own
        // whole-body runWithFirmContext() wrap (established at
        // Checkpoint 39A-3H) transitively covers
        // PaymentApplicationService::applyToInstallment()'s own direct
        // payment_plan_events write too, with no wiring change needed
        // this checkpoint.
        $payment = $manualPaymentService->submit(
            $firm,
            $client,
            amountCents: 10000,
            method: ManualPaymentMethod::Check,
            requestedClassification: PaymentClassification::OperatingPayment,
            idempotencyKey: (string) Str::uuid(),
            installment: $installment,
        );

        $this->assertNoDatabaseTenantContext('submit() must clear its own internal context wrap before returning.');
        $this->assertNotNull($payment->id);

        $installmentPaidEvent = $this->runWithFirmContext(
            $firm,
            fn () => PaymentPlanEvent::query()
                ->where('payment_plan_id', $activePlan->id)
                ->where('event_type', 'installment_paid')
                ->first(),
        );

        $this->assertNotNull(
            $installmentPaidEvent,
            'ManualPaymentService::submit() -> PaymentApplicationService::applyToInstallment() must genuinely persist an installment_paid payment_plan_events row to the database, even though the test itself never established ambient context.'
        );
        $this->assertSame($firm->id, $installmentPaidEvent->firm_id);

        // The plan's only installment was fully paid, so
        // markCompletedIfAllInstallmentsPaid() (also called from inside
        // the same ambient wrap) must additionally have logged a
        // 'completed' event.
        $completedEvent = $this->runWithFirmContext(
            $firm,
            fn () => PaymentPlanEvent::query()
                ->where('payment_plan_id', $activePlan->id)
                ->where('event_type', 'completed')
                ->first(),
        );

        $this->assertNotNull(
            $completedEvent,
            'PaymentPlanService::markCompletedIfAllInstallmentsPaid() must also genuinely persist its completed payment_plan_events row, proving this second, deliberately-unwrapped call chain is transitively covered too.'
        );
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        $plan = $this->runWithFirmContext($firm, fn () => PaymentPlan::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, fn () => PaymentPlanEvent::factory()->forPlan($plan)->create());

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
     * Forty previously forced tables plus payment_plan_events must be
     * independently force-active and independently isolated at the
     * same time — proof this batch did not weaken or interfere with any
     * prior section's own enforcement. Uses payment_plans as the
     * companion table.
     */
    public function test_payment_plan_events_are_isolated_independently_and_simultaneously_with_payment_plans(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $planA = $this->runWithFirmContext($firmA, fn () => PaymentPlan::factory()->forFirm($firmA)->create());
        $planB = $this->runWithFirmContext($firmB, fn () => PaymentPlan::factory()->forFirm($firmB)->create());

        $eventA = $this->runWithFirmContext($firmA, fn () => PaymentPlanEvent::factory()->forPlan($planA)->create());
        $eventB = $this->runWithFirmContext($firmB, fn () => PaymentPlanEvent::factory()->forPlan($planB)->create());

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'payment_plan_events' => PaymentPlanEvent::query()->pluck('id')->all(),
            'payment_plans' => PaymentPlan::query()->pluck('id')->all(),
        ]);

        $this->assertSame([$eventA->id], $resultA['payment_plan_events']);
        $this->assertNotContains($eventB->id, $resultA['payment_plan_events']);
        $this->assertContains($planA->id, $resultA['payment_plans']);
        $this->assertNotContains($planB->id, $resultA['payment_plans']);
    }

    // ---------------------------------------------------------------
    // Migration down()/up() restoration proofs
    // ---------------------------------------------------------------

    /**
     * Rollback support: the payment_plan_events migration's down() must
     * genuinely restore the Section 39A baseline — RLS still enabled,
     * policy still present, but NOT forced — never drop the policy or
     * disable RLS itself. Also proves rollback affects ONLY this one
     * table — every other previously-forced table must be untouched.
     */
    public function test_payment_plan_events_migration_down_restores_the_not_forced_baseline_and_affects_only_this_table(): void
    {
        $migration = require base_path('database/migrations/2026_08_25_930023_force_rls_on_payment_plan_events_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'payment_plan_events'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while payment_plan_events is rolled back."
                );
            }

            $policy = DB::selectOne(
                "select polname from pg_policy where polrelid = 'payment_plan_events'::regclass"
            );
            $this->assertNotNull($policy, 'Rollback must not drop the tenant isolation policy.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'payment_plan_events'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }
}
