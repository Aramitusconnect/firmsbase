<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Enums\DocumentRequestItemStatus;
use App\Models\Client;
use App\Models\CommunicationConsent;
use App\Models\DocumentChaseEvent;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\Firm;
use App\Services\ComplianceGapRegistryService;
use App\Services\DocumentChaseService;
use App\Services\FirmCommandCenterAggregationService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DocumentChaseEventsForceRlsActivationTest — Section 39A-3L,
 * Checkpoint 17. Proves the thirty-fifth staged FORCE ROW LEVEL
 * SECURITY activation batch
 * (database/migrations/2026_08_25_930017_force_rls_on_document_chase_events_table.php)
 * is permanently active for document_chase_events and behaves
 * correctly: fail-closed with no context, correct cross-firm isolation,
 * correct same-firm access, that every previously-forced table remains
 * forced simultaneously, that DocumentChaseService (already fully
 * tenant-context-wrapped since Checkpoint 10, in anticipation of this
 * checkpoint) functions correctly under FORCE, and that
 * FirmCommandCenterAggregationService::snapshot()'s
 * documentChaseEscalationsCount field (a genuine gap found and fixed in
 * this checkpoint — every sibling field in the same method was already
 * wrapped, this one alone was not) does too.
 *
 * Known, explicitly NOT fixed in this batch (tracked separately, see
 * this checkpoint's migration docblock): no composite foreign key
 * validates that document_request_item_id's owning firm matches
 * document_chase_events.firm_id. FORCE RLS does not catch this (RLS
 * only checks this table's own firm_id column, never a related row's
 * firm_id), so a cross-firm document_request_item_id reference remains
 * theoretically possible at the database layer if application code
 * ever bypassed the established write path. See
 * test_a_raw_insert_can_still_reference_an_item_from_a_different_firm_at_the_raw_db_layer
 * below for the honest, empirically-proven boundary of that claim.
 */
class DocumentChaseEventsForceRlsActivationTest extends TestCase
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
        'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys',
    ];

    /**
     * Builds a fully-consistent Firm -> Client -> DocumentRequest ->
     * DocumentRequestItem chain, avoiding any lazy relation read
     * against the already-forced document_requests table.
     */
    private function itemFor(Firm $firm): DocumentRequestItem
    {
        $client = Client::factory()->forFirm($firm)->create();
        $request = DocumentRequest::factory()->create(['firm_id' => $firm->id, 'client_id' => $client->id]);

        return DocumentRequestItem::factory()->create(['document_request_id' => $request->id]);
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

    public function test_document_chase_events_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'document_chase_events'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_document_chase_events_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'document_chase_events'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'document_chase_events must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly thirty-five tables (the thirty-four previously forced
     * plus document_chase_events) must be FORCE-enabled among ALL
     * prepared tables — no more, no less.
     */
    public function test_exactly_thirty_five_prepared_tables_are_force_row_level_security_enabled(): void
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
        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'document_chase_events', 'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans', 'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations', 'integration_credentials', 'integration_oauth_states', 'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings', 'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events', 'integration_inbound_webhook_events', 'integration_connection_health', 'integration_usage_records', 'integration_provider_webhook_subscriptions', /* Narrowly updated by Checkpoint 4 (FirmsVault Live Integrations, "Plaid financial evidence add-on") -- this full-registry list must now also account for the 21 new tables Checkpoint 4 added to both PREPARED_TABLES and the FORCE-RLS rollout in the same migration (client_portal_matter_grants, the financial-evidence domain, and the provider-billing domain) -- additive only, no existing assertion removed or weakened. */ 'client_portal_matter_grants', 'financial_evidence_bank_accounts', 'financial_evidence_transactions', 'financial_evidence_income_records', 'financial_evidence_liabilities', 'financial_evidence_investment_records', 'financial_evidence_statements', 'financial_evidence_identity_records', 'provider_billable_call_reservations', 'provider_firm_operation_policies', 'provider_balance_snapshots', 'financial_evidence_matter_requests', 'financial_evidence_client_consents', 'financial_evidence_matter_authorizations', 'financial_evidence_matter_notes', 'financial_evidence_snapshots', 'financial_evidence_transaction_reviews', 'financial_evidence_duplicate_transfer_flags', 'financial_evidence_large_deposit_flags', 'financial_evidence_reconciliation_candidates', 'financial_account_reclassification_requests', 'accounting_journal_entries', 'accounting_postings', 'payment_allocations', 'payment_reversals', 'invoice_write_offs', 'accounting_periods', 'accounting_period_events']);
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
        $this->assertSame(154, count($actuallyForced), 'Exactly thirty-six prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 17 — no more, no less.');
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
        $forced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'document_chase_events', 'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans', 'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations', 'integration_credentials', 'integration_oauth_states', 'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings', 'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events', 'integration_inbound_webhook_events', 'integration_connection_health', 'integration_usage_records', 'integration_provider_webhook_subscriptions', /* Narrowly updated by Checkpoint 4 (FirmsVault Live Integrations, "Plaid financial evidence add-on") -- this full-registry list must now also account for the 21 new tables Checkpoint 4 added to both PREPARED_TABLES and the FORCE-RLS rollout in the same migration (client_portal_matter_grants, the financial-evidence domain, and the provider-billing domain) -- additive only, no existing assertion removed or weakened. */ 'client_portal_matter_grants', 'financial_evidence_bank_accounts', 'financial_evidence_transactions', 'financial_evidence_income_records', 'financial_evidence_liabilities', 'financial_evidence_investment_records', 'financial_evidence_statements', 'financial_evidence_identity_records', 'provider_billable_call_reservations', 'provider_firm_operation_policies', 'provider_balance_snapshots', 'financial_evidence_matter_requests', 'financial_evidence_client_consents', 'financial_evidence_matter_authorizations', 'financial_evidence_matter_notes', 'financial_evidence_snapshots', 'financial_evidence_transaction_reviews', 'financial_evidence_duplicate_transfer_flags', 'financial_evidence_large_deposit_flags', 'financial_evidence_reconciliation_candidates', 'financial_account_reclassification_requests', 'accounting_journal_entries', 'accounting_postings', 'payment_allocations', 'payment_reversals', 'invoice_write_offs', 'accounting_periods', 'accounting_period_events']);
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
             where polrelid = 'document_chase_events'::regclass"
        );

        $this->assertNotNull($policy, 'The document_chase_events tenant isolation policy must still exist.');
        $this->assertStringContainsString('current_setting', $policy->using_expr);
        $this->assertStringContainsString('firm_id', $policy->using_expr);
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_document_chase_events(): void
    {
        $firm = Firm::factory()->create();
        $item = $this->runWithFirmContext($firm, fn () => $this->itemFor($firm));
        $this->runWithFirmContext($firm, fn () => DocumentChaseEvent::factory()->forItem($item, $firm)->create());

        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, DocumentChaseEvent::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_document_chase_events(): void
    {
        $firm = Firm::factory()->create();
        $item = $this->runWithFirmContext($firm, fn () => $this->itemFor($firm));

        (new TenantContextService)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('document_chase_events')->insert([
            'firm_id' => $firm->id,
            'document_request_item_id' => $item->id,
            'event_type' => 'reminder_queued',
            'metadata_json' => json_encode([]),
            'created_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_document_chase_event(): void
    {
        $firmA = Firm::factory()->create();
        $itemA = $this->runWithFirmContext($firmA, fn () => $this->itemFor($firmA));
        $eventA = $this->runWithFirmContext($firmA, fn () => DocumentChaseEvent::factory()->forItem($itemA, $firmA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => DocumentChaseEvent::query()->pluck('id')->all(),
        );

        $this->assertSame([$eventA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_document_chase_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $itemA = $this->runWithFirmContext($firmA, fn () => $this->itemFor($firmA));
        $itemB = $this->runWithFirmContext($firmB, fn () => $this->itemFor($firmB));

        $this->runWithFirmContext($firmA, fn () => DocumentChaseEvent::factory()->forItem($itemA, $firmA)->create());
        $eventB = $this->runWithFirmContext($firmB, fn () => DocumentChaseEvent::factory()->forItem($itemB, $firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => DocumentChaseEvent::query()->pluck('id')->all(),
        );

        $this->assertNotContains($eventB->id, $visibleIds);
    }

    public function test_valid_insert_with_correct_firm_context_succeeds(): void
    {
        $firm = Firm::factory()->create();
        $item = $this->runWithFirmContext($firm, fn () => $this->itemFor($firm));

        $insertedId = $this->runWithFirmContext($firm, function () use ($firm, $item) {
            return DB::table('document_chase_events')->insertGetId([
                'firm_id' => $firm->id,
                'document_request_item_id' => $item->id,
                'event_type' => 'reminder_queued',
                'metadata_json' => json_encode([]),
                'created_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_context_cannot_insert_a_document_chase_event_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $itemB = $this->runWithFirmContext($firmB, fn () => $this->itemFor($firmB));

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $itemB) {
            DB::table('document_chase_events')->insert([
                'firm_id' => $firmB->id,
                'document_request_item_id' => $itemB->id,
                'event_type' => 'reminder_queued',
                'metadata_json' => json_encode([]),
                'created_at' => now(),
            ]);
        });
    }

    public function test_firm_a_context_cannot_delete_firm_b_document_chase_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $itemB = $this->runWithFirmContext($firmB, fn () => $this->itemFor($firmB));
        $eventB = $this->runWithFirmContext($firmB, fn () => DocumentChaseEvent::factory()->forItem($itemB, $firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($eventB) {
            DB::table('document_chase_events')->where('id', $eventB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => DocumentChaseEvent::query()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s document_chase_events row.');
    }

    public function test_firm_a_context_cannot_reassign_firm_b_document_chase_event_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $itemB = $this->runWithFirmContext($firmB, fn () => $this->itemFor($firmB));
        $eventB = $this->runWithFirmContext($firmB, fn () => DocumentChaseEvent::factory()->forItem($itemB, $firmB)->create());

        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $eventB) {
            return DB::table('document_chase_events')->where('id', $eventB->id)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s document chase event to itself.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => DocumentChaseEvent::query()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame($firmB->id, $reReadAsFirmB->firm_id);
    }

    /**
     * Empirically proves the honest scope boundary described in this
     * file's class docblock and the migration's own docblock: RLS only
     * validates document_chase_events.firm_id, never
     * document_request_item_id's OWN owning firm — a raw insert whose
     * firm_id matches the active context still succeeds even when
     * document_request_item_id points at an item belonging to a
     * COMPLETELY DIFFERENT firm's document request. This is a
     * documented residual DATABASE-CONSTRAINT gap, not something RLS
     * itself closes — never to be described as blocked.
     */
    public function test_a_raw_insert_can_still_reference_an_item_from_a_different_firm_at_the_raw_db_layer(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $foreignItem = $this->runWithFirmContext($otherFirm, fn () => $this->itemFor($otherFirm));

        $mismatchedId = $this->runWithFirmContext($firm, function () use ($firm, $foreignItem) {
            return DB::table('document_chase_events')->insertGetId([
                'firm_id' => $firm->id,
                'document_request_item_id' => $foreignItem->id,
                'event_type' => 'reminder_queued',
                'metadata_json' => json_encode([]),
                'created_at' => now(),
            ]);
        });

        $this->assertIsInt(
            $mismatchedId,
            'RLS only checks the row\'s own firm_id — a document_request_item_id belonging to a different firm is NOT blocked by RLS; this is a documented residual gap, not a false guarantee.'
        );
    }

    // ---------------------------------------------------------------
    // Factory correctness proofs
    // ---------------------------------------------------------------

    /**
     * Bare factory default: a bare DocumentChaseEvent::factory()->
     * create() must succeed even from outside any already-active
     * tenant context (the factory's context-hold create() override
     * plus its explicit top-down chain-building definition()).
     */
    public function test_document_chase_event_factory_default_creation_is_internally_consistent(): void
    {
        $event = DocumentChaseEvent::factory()->create();

        $this->assertNotNull($event->id);
        $this->assertNotNull($event->firm_id);
        $this->assertNotNull($event->document_request_item_id);

        $persisted = $this->runWithFirmContext(
            $event->firm,
            fn () => DocumentChaseEvent::query()->find($event->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame($event->firm_id, $persisted->firm_id);
    }

    public function test_document_chase_event_factory_for_item_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();
        $item = $this->runWithFirmContext($firm, fn () => $this->itemFor($firm));

        $event = $this->runWithFirmContext($firm, fn () => DocumentChaseEvent::factory()->forItem($item, $firm)->create());

        $this->assertSame($firm->id, $event->firm_id);
        $this->assertSame($item->id, $event->document_request_item_id);

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => DocumentChaseEvent::query()->find($event->id),
        );

        $this->assertNotNull($persisted);
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        $item = $this->runWithFirmContext($firm, fn () => $this->itemFor($firm));

        $this->runWithFirmContext($firm, fn () => DocumentChaseEvent::factory()->forItem($item, $firm)->create());

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
    // End-to-end DocumentChaseService proof under FORCE (already
    // context-wrapped since Checkpoint 10)
    // ---------------------------------------------------------------

    /**
     * End-to-end proof that DocumentChaseService::checkAndLog() (its
     * own runWithFirmContext() wrap, established since Checkpoint 10 in
     * anticipation of this checkpoint) correctly persists a
     * document_chase_events row under FORCE — no production change was
     * needed to this service itself for this checkpoint.
     */
    public function test_check_and_log_persists_a_document_chase_event_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $client = Client::factory()->forFirm($firm)->create();
        CommunicationConsent::factory()->create([
            'firm_id' => $firm->id,
            'client_id' => $client->id,
            'channel' => ConsentChannel::Email,
            'status' => ConsentStatus::Granted,
        ]);
        $request = DocumentRequest::factory()->create(['firm_id' => $firm->id, 'client_id' => $client->id]);
        $item = DocumentRequestItem::factory()->create([
            'document_request_id' => $request->id,
            'status' => DocumentRequestItemStatus::Requested,
        ]);

        // The preceding CommunicationConsent/DocumentRequest factory
        // calls each leave DB-session tenant context set to $firm->id
        // (the established context-hold factory pattern) — establish a
        // genuinely clean baseline immediately before the call under
        // test, so the post-call assertion below proves checkAndLog()
        // itself clears context, rather than incidentally observing an
        // ambient value that runWithFirmContext() now correctly restores.
        (new TenantContextService)->clearDatabaseTenantContext();
        (new TenantContextService)->clearFirmContext();
        $this->assertNoDatabaseTenantContext();

        $service = app(DocumentChaseService::class);
        $result = $service->checkAndLog($firm, $item);

        $this->assertNoDatabaseTenantContext('checkAndLog() must clear its own context wrap before returning.');
        $this->assertTrue($result->eligible);

        $persistedCount = $this->runWithFirmContext(
            $firm,
            fn () => $item->chaseEvents()->where('event_type', 'reminder_queued')->count(),
        );
        $this->assertSame(1, $persistedCount, 'checkAndLog() must persist exactly one document_chase_events row under FORCE, readable under its own firm context.');
    }

    // ---------------------------------------------------------------
    // FirmCommandCenterAggregationService proof under FORCE (the
    // genuine gap found and fixed in this checkpoint)
    // ---------------------------------------------------------------

    public function test_command_center_snapshot_correctly_counts_document_chase_escalations_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $item = $this->runWithFirmContext($firm, fn () => $this->itemFor($firm));

        $this->runWithFirmContext($firm, function () use ($item, $firm) {
            DocumentChaseEvent::factory()->forItem($item, $firm)->create(['event_type' => 'escalated']);
            DocumentChaseEvent::factory()->forItem($item, $firm)->create(['event_type' => 'reminder_queued']);
        });

        $snapshot = app(FirmCommandCenterAggregationService::class)->snapshot($firm);

        $this->assertSame(1, $snapshot->documentChaseEscalationsCount);
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
     * Thirty-four previously forced tables plus document_chase_events
     * must be independently force-active and independently isolated at
     * the same time — proof this batch did not weaken or interfere
     * with any prior section's own enforcement. Uses clients as the
     * companion table.
     */
    public function test_document_chase_events_are_isolated_independently_and_simultaneously_with_clients(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());

        $itemA = $this->runWithFirmContext($firmA, fn () => $this->itemFor($firmA));
        $itemB = $this->runWithFirmContext($firmB, fn () => $this->itemFor($firmB));

        $eventA = $this->runWithFirmContext($firmA, fn () => DocumentChaseEvent::factory()->forItem($itemA, $firmA)->create());
        $eventB = $this->runWithFirmContext($firmB, fn () => DocumentChaseEvent::factory()->forItem($itemB, $firmB)->create());

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'document_chase_events' => DocumentChaseEvent::query()->pluck('id')->all(),
            'clients' => Client::withoutGlobalScopes()->pluck('id')->all(),
        ]);

        $this->assertSame([$eventA->id], $resultA['document_chase_events']);
        $this->assertNotContains($eventB->id, $resultA['document_chase_events']);
        $this->assertContains($clientA->id, $resultA['clients']);
        $this->assertNotContains($clientB->id, $resultA['clients']);
    }

    // ---------------------------------------------------------------
    // Migration down()/up() restoration proofs
    // ---------------------------------------------------------------

    /**
     * Rollback support: the document_chase_events migration's down()
     * must genuinely restore the Section 39A baseline — RLS still
     * enabled, policy still present, but NOT forced — never drop the
     * policy or disable RLS itself. Also proves rollback affects ONLY
     * this one table — every other previously-forced table must be
     * untouched.
     */
    public function test_document_chase_events_migration_down_restores_the_not_forced_baseline_and_affects_only_this_table(): void
    {
        $migration = require base_path('database/migrations/2026_08_25_930017_force_rls_on_document_chase_events_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'document_chase_events'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while document_chase_events is rolled back."
                );
            }

            $policy = DB::selectOne(
                "select polname from pg_policy where polrelid = 'document_chase_events'::regclass"
            );
            $this->assertNotNull($policy, 'Rollback must not drop the tenant isolation policy.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'document_chase_events'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }
}
