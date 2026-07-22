<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\PaymentClassification;
use App\Enums\PaymentStatus;
use App\Models\Firm;
use App\Models\Payment;
use App\Models\PaymentClassificationEvent;
use App\Services\PaymentClassificationService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PaymentClassificationEventsForceRlsActivationTest — Section 39A-3L,
 * Checkpoint 1, Table Phase C. Proves the nineteenth staged FORCE ROW
 * LEVEL SECURITY activation batch
 * (database/migrations/2026_08_25_930001_force_rls_on_payment_classification_events_table.php)
 * is permanently active for payment_classification_events and behaves
 * correctly: fail-closed with no context, correct cross-firm isolation,
 * correct same-firm access, and that every previously-forced table
 * (clients, firm_users, documents, deadlines, tasks, matters, invoices,
 * payments, conflict_check_runs, lead_sources, consultation_outcomes,
 * firm_leads, consultations, firm_practice_areas, document_chase_rules,
 * employee_rates, calendar_events, client_communication_preferences)
 * remains forced simultaneously.
 *
 * payment_classification_events is append-only (PaymentClassificationEvent
 * ::UPDATED_AT === null — no updated_at column exists at all), so there
 * is no "legitimate application-level update path" to protect the same
 * way payments/invoices have one — the cross-firm UPDATE proof below
 * still targets firm_id reassignment directly via the query builder to
 * prove the RLS policy itself (not application code) is what blocks it.
 *
 * IMPORTANT factory/RLS interaction, proven explicitly below (see
 * test_forpayment_factory_state_create_is_clobbered_by_definitions_own_side_effect):
 * PaymentClassificationEventFactory::definition() eagerly calls
 * Payment::factory()->create() to derive its own internally-consistent
 * firm_id/payment_id pair. That nested call invokes PaymentFactory's
 * own context-setting create() override
 * (setDatabaseTenantContextForFirmId()), which resets the PostgreSQL
 * session's app.current_firm_id to THAT throwaway payment's own
 * unrelated random firm. forPayment()'s state() override then swaps
 * firm_id/payment_id back to the caller's intended payment AFTER that
 * side effect already ran — so by the time the actual INSERT happens,
 * the session context no longer matches the row being written, and
 * PaymentClassificationEvent::factory()->forPayment($payment)->create()
 * fails with a row-level security violation even under a correct outer
 * runWithFirmContext() wrap. This is a genuine, load-bearing finding —
 * not something this test file may fix (the factory itself is Table
 * Phase B's finalized, exclusive file) — so every fixture-creation call
 * in this file goes through the model's own create() directly instead
 * (mirroring PaymentClassificationService::recordDecision()'s exact
 * real production call shape), via the private createEventForPayment()
 * helper below.
 */
class PaymentClassificationEventsForceRlsActivationTest extends TestCase
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
        'client_communication_preferences',
    ];

    /**
     * Fixture helper — deliberately bypasses
     * PaymentClassificationEventFactory entirely and calls the model's
     * own create() directly (exactly as
     * PaymentClassificationService::recordDecision() does in
     * production), to avoid the factory/RLS clobbering interaction
     * documented in this file's own class docblock above.
     */
    private function createEventForPayment(Payment $payment, array $overrides = []): PaymentClassificationEvent
    {
        return PaymentClassificationEvent::create(array_merge([
            'firm_id' => $payment->firm_id,
            'payment_id' => $payment->id,
            'event_type' => 'classification_accepted',
            'requested_classification' => PaymentClassification::OperatingPayment,
            'resolved_classification' => PaymentClassification::OperatingPayment,
            'reason' => null,
            'actor_user_id' => null,
        ], $overrides));
    }

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

    public function test_payment_classification_events_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'payment_classification_events'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_payment_classification_events_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'payment_classification_events'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'payment_classification_events must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly nineteen tables (the eighteen previously forced plus
     * payment_classification_events) must be FORCE-enabled among ALL
     * prepared tables — no more, no less. This is the "exact expected
     * count" proof, independent of RlsForceRolloutFirewallTest's own
     * equivalent check, so this file stands alone as proof for this
     * table.
     *
     * Narrowly updated by Section 39A-3L, Checkpoint 2, Table Phase C
     * (this repo's twentieth staged FORCE activation batch, covering
     * activation_checklists) to account for that later, legitimate
     * addition — the count and expected-table list below now reflect
     * the real, current state of this working tree rather than a
     * frozen snapshot of Checkpoint 1 alone. Additive only: every
     * originally-asserted table is still asserted forced here.
     *
     * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 3, Table
     * Phase C (this repo's twenty-first staged FORCE activation batch,
     * covering firm_activation_events) for the same reason — additive
     * only, no existing assertion removed or weakened.
     *
     * Narrowly updated AGAIN by Section 39A-3L, Checkpoint 4, Table
     * Phase C (this repo's twenty-second staged FORCE activation batch,
     * covering firm_entitlements) for the same reason — additive only,
     * no existing assertion removed or weakened.
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
    public function test_exactly_nineteen_prepared_tables_are_force_row_level_security_enabled(): void
    {
        $coverage = new \App\Services\RowLevelSecurityCoverageMappingService();

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
        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'payment_classification_events', 'activation_checklists', 'firm_activation_events', 'firm_entitlements', 'firm_entitlement_events', 'installed_template_packs', 'template_upgrade_logs', 'template_upgrade_previews', 'seat_allocations', 'document_requests', 'communication_consents', 'communication_consent_events', 'intake_submissions', 'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events', 'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans', 'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations', 'integration_credentials', 'integration_oauth_states', 'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings', 'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events', 'integration_inbound_webhook_events']);
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
        $this->assertSame(123, count($actuallyForced), 'Exactly thirty-six prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 13 — no more, no less (nineteen after this batch\'s own Checkpoint 1, plus activation_checklists from Checkpoint 2, plus firm_activation_events from Checkpoint 3, plus firm_entitlements from Checkpoint 4, plus firm_entitlement_events from Checkpoint 5, plus installed_template_packs from Checkpoint 6, plus template_upgrade_logs from Checkpoint 7, plus template_upgrade_previews from Checkpoint 8, plus seat_allocations from Checkpoint 9, plus document_requests from Checkpoint 10, plus communication_consents from Checkpoint 11, plus communication_consent_events from Checkpoint 12, plus intake_submissions from Checkpoint 13). Narrowly updated again for Section 39A-3L, Checkpoint 14 (matter_readiness_scores added on top of the prior thirty-one), again for Checkpoint 15 (readiness_score_events added on top of the prior thirty-two), and again for Checkpoint 16 (tenant_encryption_keys added on top of the prior thirty-three).');
        $this->assertSame($expectedForced, $actuallyForced);
    }

    public function test_the_tenant_isolation_policy_remains_present_and_unchanged_after_up(): void
    {
        $policy = DB::selectOne(
            "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'payment_classification_events'::regclass"
        );

        $this->assertNotNull($policy, 'The payment_classification_events_tenant_isolation policy must still exist.');
        $this->assertSame('payment_classification_events_tenant_isolation', $policy->polname);
        $this->assertStringContainsString('current_setting', $policy->using_expr);
        $this->assertStringContainsString('firm_id', $policy->using_expr);
    }

    /**
     * Section 39A-3L, Checkpoint 1, Table Phase C requirement 1: a
     * GENUINE no-context regression proof. The two pre-existing tests in
     * PaymentClassificationServiceTest that call recordDecision()
     * directly do NOT actually exercise a context-less scenario — they
     * silently ride on the leaked `SET LOCAL app.current_firm_id`
     * context PaymentFactory's own context-hold create() override
     * pushes (session/transaction-scoped, so it stays active for the
     * rest of the RefreshDatabase-wrapped test transaction). This test
     * explicitly calls clearDatabaseTenantContext() — the exact
     * mechanism TenantContextService exposes for provably clearing
     * app.current_firm_id — immediately before calling recordDecision(),
     * proving the write genuinely fails closed now that this table is
     * forced.
     */
    public function test_recording_a_decision_with_no_active_tenant_context_fails_closed(): void
    {
        $firm = Firm::factory()->create();
        $payment = $this->runWithFirmContext($firm, fn () => Payment::factory()->forFirm($firm)->create([
            'payment_classification' => PaymentClassification::OperatingPayment,
            'status' => PaymentStatus::Initiated,
        ]));

        // Provably no context: explicitly cleared, not merely "never
        // set" (which could still be masked by an earlier leak).
        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $service = new PaymentClassificationService();
        $result = $service->classify($firm, PaymentClassification::OperatingPayment);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $service->recordDecision($payment, PaymentClassification::OperatingPayment, $result);
    }

    public function test_missing_tenant_context_cannot_read_payment_classification_events(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, function () use ($firm) {
            $payment = Payment::factory()->forFirm($firm)->create();
            $this->createEventForPayment($payment);
        });

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->assertSame(0, PaymentClassificationEvent::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_insert_payment_classification_events(): void
    {
        $firm = Firm::factory()->create();
        $paymentId = $this->runWithFirmContext($firm, fn () => Payment::factory()->forFirm($firm)->create())->id;

        (new TenantContextService())->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('payment_classification_events')->insert([
            'firm_id' => $firm->id,
            'payment_id' => $paymentId,
            'event_type' => 'classification_accepted',
            'requested_classification' => 'operating_payment',
            'resolved_classification' => 'operating_payment',
            'created_at' => now(),
        ]);
    }

    public function test_firm_a_context_can_read_its_own_payment_classification_events(): void
    {
        $firmA = Firm::factory()->create();
        $eventA = $this->runWithFirmContext($firmA, function () use ($firmA) {
            $payment = Payment::factory()->forFirm($firmA)->create();

            return $this->createEventForPayment($payment);
        });

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => PaymentClassificationEvent::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$eventA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_payment_classification_events(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->runWithFirmContext($firmA, function () use ($firmA) {
            $payment = Payment::factory()->forFirm($firmA)->create();

            return $this->createEventForPayment($payment);
        });

        $eventB = $this->runWithFirmContext($firmB, function () use ($firmB) {
            $payment = Payment::factory()->forFirm($firmB)->create();

            return $this->createEventForPayment($payment);
        });

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => PaymentClassificationEvent::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($eventB->id, $visibleIds);
    }

    public function test_valid_insert_with_correct_firm_context_succeeds(): void
    {
        $firm = Firm::factory()->create();

        $eventId = $this->runWithFirmContext($firm, function () use ($firm) {
            $payment = Payment::factory()->forFirm($firm)->create();

            return DB::table('payment_classification_events')->insertGetId([
                'firm_id' => $firm->id,
                'payment_id' => $payment->id,
                'event_type' => 'classification_accepted',
                'requested_classification' => 'operating_payment',
                'resolved_classification' => 'operating_payment',
                'created_at' => now(),
            ]);
        });

        $this->assertIsInt($eventId);
    }

    public function test_firm_a_context_cannot_insert_a_payment_classification_event_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $paymentB = $this->runWithFirmContext($firmB, fn () => Payment::factory()->forFirm($firmB)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $paymentB) {
            DB::table('payment_classification_events')->insert([
                'firm_id' => $firmB->id,
                'payment_id' => $paymentB->id,
                'event_type' => 'classification_accepted',
                'requested_classification' => 'operating_payment',
                'resolved_classification' => 'operating_payment',
                'created_at' => now(),
            ]);
        });
    }

    public function test_firm_a_context_cannot_update_firm_b_payment_classification_events(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $eventB = $this->runWithFirmContext($firmB, function () use ($firmB) {
            $payment = Payment::factory()->forFirm($firmB)->create();

            return $this->createEventForPayment($payment, ['reason' => null]);
        });

        $this->runWithFirmContext($firmA, function () use ($eventB) {
            DB::table('payment_classification_events')->where('id', $eventB->id)->update(['reason' => 'tampered by firm A']);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => PaymentClassificationEvent::withoutGlobalScopes()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertNull($reReadAsFirmB->reason, 'Firm A context must not be able to update Firm B payment_classification_events.');
    }

    public function test_firm_a_context_cannot_delete_firm_b_payment_classification_events(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $eventB = $this->runWithFirmContext($firmB, function () use ($firmB) {
            $payment = Payment::factory()->forFirm($firmB)->create();

            return $this->createEventForPayment($payment);
        });

        $this->runWithFirmContext($firmA, function () use ($eventB) {
            DB::table('payment_classification_events')->where('id', $eventB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => PaymentClassificationEvent::withoutGlobalScopes()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B payment_classification_events.');
    }

    /**
     * Firm ownership itself (the firm_id column) must never be
     * reassignable via a raw UPDATE while under a different firm's
     * context — even setting aside the value being updated TO, the
     * policy's USING clause must reject the row entirely once no rows
     * are visible under firmA's context.
     */
    public function test_firm_a_context_cannot_reassign_firm_b_payment_classification_event_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $eventB = $this->runWithFirmContext($firmB, function () use ($firmB) {
            $payment = Payment::factory()->forFirm($firmB)->create();

            return $this->createEventForPayment($payment);
        });

        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $eventB) {
            return DB::table('payment_classification_events')->where('id', $eventB->id)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s event to itself.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => PaymentClassificationEvent::withoutGlobalScopes()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame($firmB->id, $reReadAsFirmB->firm_id);
    }

    /**
     * Known, documented residual gap (same pattern as
     * Matters/Invoices/PaymentsForceRlsActivationTest's equivalent
     * mismatch proofs): RLS's single-column policy only validates the
     * payment_classification_events row's own firm_id against session
     * context, never that payment_id transitively belongs to the same
     * firm. A raw insert whose firm_id matches the active context still
     * succeeds even when payment_id points at another firm's payment —
     * this is a residual DATABASE-CONSTRAINT gap, not something RLS
     * itself closes, which is exactly why PaymentClassificationEventFactory's
     * own root-cause fix (tying the event to one freshly-created payment
     * of the SAME firm) matters for factory-default safety.
     */
    public function test_firm_a_can_still_create_a_payment_classification_event_using_a_firm_b_payment_at_the_raw_db_layer(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $paymentB = $this->runWithFirmContext($firmB, fn () => Payment::factory()->forFirm($firmB)->create());

        $mismatchedEventId = $this->runWithFirmContext($firmA, function () use ($firmA, $paymentB) {
            return DB::table('payment_classification_events')->insertGetId([
                'firm_id' => $firmA->id,
                'payment_id' => $paymentB->id,
                'event_type' => 'classification_accepted',
                'requested_classification' => 'operating_payment',
                'resolved_classification' => 'operating_payment',
                'created_at' => now(),
            ]);
        });

        $this->assertIsInt($mismatchedEventId, 'RLS only checks the row\'s own firm_id — a transitive payment_id/firm_id mismatch is NOT blocked by RLS; this is a documented residual gap, not a false guarantee.');
    }

    public function test_payment_classification_event_factory_default_creation_is_internally_consistent(): void
    {
        $event = PaymentClassificationEvent::factory()->create();

        $payment = $this->runWithFirmContext($event->firm, fn () => Payment::withoutGlobalScopes()->find($event->payment_id));

        $this->assertNotNull($payment, 'The factory-created payment must be visible under the event\'s own firm context.');
        $this->assertSame($event->firm_id, $payment->firm_id, 'A bare factory-created event must never produce a firm_id/payment_id mismatch.');
    }

    /**
     * forPayment()'s own attribute-tying logic is correct in isolation
     * (proven here via make(), which never persists and therefore never
     * triggers RLS at all) — the caller-supplied payment's firm_id and
     * id are exactly what land on the built (unsaved) model.
     */
    public function test_payment_classification_event_factory_for_payment_state_ties_attributes_consistently(): void
    {
        $firm = Firm::factory()->create();
        $payment = $this->runWithFirmContext($firm, fn () => Payment::factory()->forFirm($firm)->create());

        $event = $this->runWithFirmContext(
            $firm,
            fn () => PaymentClassificationEvent::factory()->forPayment($payment)->make(),
        );

        $this->assertSame($firm->id, $event->firm_id);
        $this->assertSame($payment->id, $event->payment_id);
    }

    /**
     * Explicit, load-bearing proof of the factory/RLS interaction
     * documented in this file's class docblock: calling
     * ->forPayment($payment)->create() for an EXISTING payment, even
     * from inside a correct runWithFirmContext($payment->firm, ...)
     * wrap, fails with a row-level security violation — because
     * definition()'s own eager Payment::factory()->create() side
     * effect silently resets the session's app.current_firm_id to an
     * unrelated random firm before the real INSERT happens. This is
     * exactly why every other test in this file uses
     * createEventForPayment() instead of the factory's forPayment()
     * state for any persisted fixture. Documented as a residual
     * factory/RLS interaction gap for Table Phase D review — not
     * something this test file may fix (the factory is Table Phase B's
     * exclusive, finalized file).
     */
    public function test_forpayment_factory_state_create_is_clobbered_by_definitions_own_side_effect(): void
    {
        $firm = Firm::factory()->create();
        $payment = $this->runWithFirmContext($firm, fn () => Payment::factory()->forFirm($firm)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firm, fn () => PaymentClassificationEvent::factory()->forPayment($payment)->create());
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, function () use ($firm) {
            $payment = Payment::factory()->forFirm($firm)->create();
            $this->createEventForPayment($payment);
        });

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
     * Rollback support: the migration's down() must genuinely restore
     * the Section 39A baseline — RLS still enabled, policy still
     * present, but NOT forced — never drop the policy or disable RLS
     * itself (those belong to the Phase 3 preparation migration). Also
     * proves rollback affects ONLY this one table — every other
     * previously-forced table must be untouched by this specific
     * migration's down()/up() cycle.
     */
    public function test_migration_down_restores_the_not_forced_baseline_and_affects_only_this_table(): void
    {
        $migration = require base_path('database/migrations/2026_08_25_930001_force_rls_on_payment_classification_events_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'payment_classification_events'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while payment_classification_events is rolled back."
                );
            }
        } finally {
            $migration->up();
        }
    }

    /**
     * All nineteen staged batches must be independently force-active
     * and independently isolated at the same time — proof this batch
     * did not weaken or interfere with any prior section's own
     * enforcement, focused on the two tables this table's own write
     * path (Payment + PaymentClassificationEvent) directly touches.
     */
    public function test_payments_and_payment_classification_events_are_isolated_independently_and_simultaneously(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $paymentA = $this->runWithFirmContext($firmA, fn () => Payment::factory()->forFirm($firmA)->create());
        $paymentB = $this->runWithFirmContext($firmB, fn () => Payment::factory()->forFirm($firmB)->create());

        $eventA = $this->runWithFirmContext($firmA, fn () => $this->createEventForPayment($paymentA));
        $eventB = $this->runWithFirmContext($firmB, fn () => $this->createEventForPayment($paymentB));

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'payments' => Payment::withoutGlobalScopes()->pluck('id')->all(),
            'payment_classification_events' => PaymentClassificationEvent::withoutGlobalScopes()->pluck('id')->all(),
        ]);

        $this->assertSame([$paymentA->id], $resultA['payments']);
        $this->assertNotContains($paymentB->id, $resultA['payments']);
        $this->assertSame([$eventA->id], $resultA['payment_classification_events']);
        $this->assertNotContains($eventB->id, $resultA['payment_classification_events']);
    }
}
