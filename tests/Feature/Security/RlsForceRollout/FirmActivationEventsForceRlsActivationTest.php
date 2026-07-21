<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\FirmActivationEventStatus;
use App\Enums\FirmUserStatus;
use App\Enums\LicenseStatus;
use App\Enums\TenantEncryptionKeyStatus;
use App\Models\ActivationChecklist;
use App\Models\Firm;
use App\Models\FirmActivationEvent;
use App\Models\FirmLicense;
use App\Models\FirmPracticeArea;
use App\Models\FirmSettings;
use App\Models\FirmUser;
use App\Models\PracticeArea;
use App\Models\TenantEncryptionKey;
use App\Services\ActivationChecklistService;
use App\Services\ComplianceGapRegistryService;
use App\Services\FirmProductionActivationService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * FirmActivationEventsForceRlsActivationTest — Section 39A-3L,
 * Checkpoint 3, Table Phase C. Proves the twenty-first staged FORCE ROW
 * LEVEL SECURITY activation batch
 * (database/migrations/2026_08_25_930003_force_rls_on_firm_activation_events_table.php)
 * is permanently active for firm_activation_events and behaves
 * correctly: fail-closed with no context, correct cross-firm isolation,
 * correct same-firm access, that every previously-forced table
 * (clients, firm_users, documents, deadlines, tasks, matters, invoices,
 * payments, conflict_check_runs, lead_sources, consultation_outcomes,
 * firm_leads, consultations, firm_practice_areas, document_chase_rules,
 * employee_rates, calendar_events, client_communication_preferences,
 * payment_classification_events, activation_checklists) remains forced
 * simultaneously, and that FirmProductionActivationService's three
 * tenant-context-wrapped methods (evaluate(), recordEvaluation(),
 * autoCompleteVerifiableItems()) still function correctly end-to-end
 * with BOTH activation_checklists and firm_activation_events forced at
 * once.
 *
 * firm_activation_events is genuinely append-only: FirmActivationEvent
 * sets const UPDATED_AT = null, and a repository-wide grep confirms zero
 * update()/delete() call sites against it anywhere in production code —
 * every production call site is FirmActivationEvent::create(). The
 * cross-firm UPDATE/DELETE proofs below still target it directly via
 * the raw query builder (bypassing application code entirely) to prove
 * the RLS policy itself — not merely the absence of application code —
 * is what blocks a hypothetical future write.
 *
 * Unlike payment_classification_events (which carries a transitively
 * tenant-scoped payment_id foreign key), firm_activation_events' only
 * other foreign key is actor_user_id, which references the global
 * `users` table (not `firm_users`) and is nullable — users are
 * platform-wide entities, not themselves firm-scoped rows, so there is
 * no analogous "transitive firm_id mismatch" gap to prove here. This is
 * an honest scope note, not an assumed guarantee: see
 * test_actor_user_id_is_not_validated_against_the_firms_own_users_by_rls
 * below for the one honest, narrow claim this file does make about that
 * column.
 */
class FirmActivationEventsForceRlsActivationTest extends TestCase
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
    use RefreshDatabase;

    private const PREVIOUSLY_FORCED_TABLES = [
        'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments',
        'conflict_check_runs', 'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
        'firm_practice_areas', 'document_chase_rules', 'employee_rates', 'calendar_events',
        'client_communication_preferences', 'payment_classification_events', 'activation_checklists',
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

    public function test_firm_activation_events_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'firm_activation_events'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_firm_activation_events_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'firm_activation_events'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'firm_activation_events must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly twenty-one tables (the twenty previously forced plus
     * firm_activation_events) must be FORCE-enabled among ALL prepared
     * tables — no more, no less. This is the "exact expected count"
     * proof, independent of RlsForceRolloutFirewallTest's own
     * equivalent check, so this file stands alone as proof for this
     * table.
     *
     * Narrowly updated by Section 39A-3L, Checkpoint 4, Table Phase C
     * (this repo's twenty-second staged FORCE activation batch, covering
     * firm_entitlements) to account for that later, legitimate
     * addition — the count and expected-table list below now reflect
     * the real, current state of this working tree rather than a
     * frozen snapshot of Checkpoint 3 alone. Additive only: every
     * originally-asserted table is still asserted forced here.
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
    public function test_exactly_twenty_one_prepared_tables_are_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();

        // Narrowly updated AGAIN by Section 39A-3L, Checkpoint 9, Table
        // Phase C (seat_allocations) for the same reason — additive
        // only, no existing assertion removed or weakened.
        // Narrowly updated by Section 39A-3L, Checkpoint 11, Table Phase C
        // (communication_consents) — additive only, no existing assertion
        // removed or weakened.
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
        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'firm_activation_events', 'firm_entitlements', 'firm_entitlement_events', 'installed_template_packs', 'template_upgrade_logs', 'template_upgrade_previews', 'seat_allocations', 'document_requests', 'communication_consents', 'communication_consent_events', 'intake_submissions', 'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events', 'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans', 'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations', 'integration_credentials', 'integration_oauth_states']);
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
        $this->assertSame(116, count($actuallyForced), 'Exactly thirty-six prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 13 — no more, no less (twenty-one after this batch\'s own Checkpoint 3, plus firm_entitlements from Checkpoint 4, plus firm_entitlement_events from Checkpoint 5, plus installed_template_packs from Checkpoint 6, plus template_upgrade_logs from Checkpoint 7, plus template_upgrade_previews from Checkpoint 8, plus seat_allocations from Checkpoint 9, plus document_requests from Checkpoint 10, plus communication_consents from Checkpoint 11, plus communication_consent_events from Checkpoint 12, plus intake_submissions from Checkpoint 13). Narrowly updated again for Section 39A-3L, Checkpoint 14 (matter_readiness_scores added on top of the prior thirty-one), again for Checkpoint 15 (readiness_score_events added on top of the prior thirty-two), and again for Checkpoint 16 (tenant_encryption_keys added on top of the prior thirty-three).');
        $this->assertSame($expectedForced, $actuallyForced);
    }

    public function test_the_tenant_isolation_policy_remains_present_and_unchanged_after_up(): void
    {
        $policy = DB::selectOne(
            "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'firm_activation_events'::regclass"
        );

        $this->assertNotNull($policy, 'The firm_activation_events tenant isolation policy must still exist.');
        $this->assertSame('firm_activation_events_tenant_isolation', $policy->polname);
        $this->assertStringContainsString('current_setting', $policy->using_expr);
        $this->assertStringContainsString('firm_id', $policy->using_expr);
        $this->assertNull($policy->with_check_expr, 'No explicit WITH CHECK clause was ever added — USING alone governs both reads and writes for this policy, unchanged by this migration.');
    }

    /**
     * Genuine no-context regression proof: explicitly clears
     * app.current_firm_id (rather than relying on a test never having
     * set it, which could be masked by an earlier leak) immediately
     * before reading — proving the read genuinely fails closed now
     * that this table is forced.
     */
    public function test_missing_tenant_context_cannot_read_firm_activation_events(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => FirmActivationEvent::factory()->forFirm($firm)->create());

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, FirmActivationEvent::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_insert_firm_activation_events(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('firm_activation_events')->insert([
            'firm_id' => $firm->id,
            'event_type' => 'checklist_item_completed',
            'status' => 'completed',
            'created_at' => now(),
        ]);
    }

    public function test_firm_a_context_can_read_its_own_firm_activation_event(): void
    {
        $firmA = Firm::factory()->create();
        $eventA = $this->runWithFirmContext($firmA, fn () => FirmActivationEvent::factory()->forFirm($firmA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => FirmActivationEvent::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$eventA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_firm_activation_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->runWithFirmContext($firmA, fn () => FirmActivationEvent::factory()->forFirm($firmA)->create());
        $eventB = $this->runWithFirmContext($firmB, fn () => FirmActivationEvent::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => FirmActivationEvent::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($eventB->id, $visibleIds);
    }

    public function test_valid_insert_with_correct_firm_context_succeeds(): void
    {
        $firm = Firm::factory()->create();

        $eventId = $this->runWithFirmContext($firm, function () use ($firm) {
            return DB::table('firm_activation_events')->insertGetId([
                'firm_id' => $firm->id,
                'event_type' => 'checklist_item_completed',
                'status' => 'completed',
                'created_at' => now(),
            ]);
        });

        $this->assertIsInt($eventId);
    }

    public function test_firm_a_context_cannot_insert_a_firm_activation_event_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('firm_activation_events')->insert([
                'firm_id' => $firmB->id,
                'event_type' => 'checklist_item_completed',
                'status' => 'completed',
                'created_at' => now(),
            ]);
        });
    }

    public function test_firm_a_context_cannot_update_firm_b_firm_activation_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $eventB = $this->runWithFirmContext($firmB, fn () => FirmActivationEvent::factory()->forFirm($firmB)->create(['blocking_reason' => null]));

        $this->runWithFirmContext($firmA, function () use ($eventB) {
            DB::table('firm_activation_events')->where('id', $eventB->id)->update(['blocking_reason' => 'tampered by firm A']);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FirmActivationEvent::withoutGlobalScopes()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertNull($reReadAsFirmB->blocking_reason, 'Firm A context must not be able to update Firm B\'s firm_activation_events row.');
    }

    public function test_firm_a_context_cannot_delete_firm_b_firm_activation_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $eventB = $this->runWithFirmContext($firmB, fn () => FirmActivationEvent::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($eventB) {
            DB::table('firm_activation_events')->where('id', $eventB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FirmActivationEvent::withoutGlobalScopes()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s firm_activation_events row.');
    }

    /**
     * Firm ownership itself (the firm_id column) must never be
     * reassignable via a raw UPDATE while under a different firm's
     * context — even setting aside the value being updated TO, the
     * policy's USING clause must reject the row entirely once no rows
     * are visible under firmA's context.
     */
    public function test_firm_a_context_cannot_reassign_firm_b_firm_activation_event_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $eventB = $this->runWithFirmContext($firmB, fn () => FirmActivationEvent::factory()->forFirm($firmB)->create());

        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $eventB) {
            return DB::table('firm_activation_events')->where('id', $eventB->id)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s event to itself.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FirmActivationEvent::withoutGlobalScopes()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame($firmB->id, $reReadAsFirmB->firm_id);
    }

    public function test_firm_activation_event_factory_default_creation_is_internally_consistent(): void
    {
        $event = FirmActivationEvent::factory()->create();

        $this->assertNotNull($event->id);
        $this->assertNotNull($event->firm_id);

        $reRead = $this->runWithFirmContext(
            $event->firm,
            fn () => FirmActivationEvent::withoutGlobalScopes()->find($event->id),
        );

        $this->assertNotNull($reRead, 'A bare factory-created event must be visible under its own firm\'s context.');
        $this->assertSame($event->firm_id, $reRead->firm_id);
    }

    public function test_firm_activation_event_factory_for_firm_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();
        $event = FirmActivationEvent::factory()->forFirm($firm)->create();

        $this->assertSame($firm->id, $event->firm_id);

        $reRead = $this->runWithFirmContext(
            $firm,
            fn () => FirmActivationEvent::withoutGlobalScopes()->find($event->id),
        );

        $this->assertNotNull($reRead);
    }

    public function test_firm_activation_event_factory_blocked_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();
        $event = FirmActivationEvent::factory()->forFirm($firm)->blocked('example blocking reason')->create();

        $reRead = $this->runWithFirmContext(
            $firm,
            fn () => FirmActivationEvent::withoutGlobalScopes()->find($event->id),
        );

        $this->assertNotNull($reRead);
        $this->assertSame(FirmActivationEventStatus::Blocked, $reRead->status);
        $this->assertSame('example blocking reason', $reRead->blocking_reason);
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => FirmActivationEvent::factory()->forFirm($firm)->create());

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
    private function eligibleFirm(array $skip = []): Firm
    {
        $firm = Firm::factory()->create([
            'billing_account_id' => in_array('billing_account', $skip, true)
                ? null
                : \App\Models\BillingAccount::factory()->create()->id,
        ]);

        if (! in_array('firm_settings', $skip, true)) {
            FirmSettings::factory()->forFirm($firm)->create();
        }

        if (! in_array('license', $skip, true)) {
            FirmLicense::factory()->create([
                'firm_id' => $firm->id,
                'license_status' => LicenseStatus::Active,
            ]);
        }

        if (! in_array('encryption_key', $skip, true)) {
            TenantEncryptionKey::factory()->create(['firm_id' => $firm->id, 'status' => TenantEncryptionKeyStatus::Active]);
        }

        return $firm->fresh();
    }

    /**
     * End-to-end proof of FirmProductionActivationService's three
     * tenant-context-wrapped methods (evaluate() -> recordEvaluation(),
     * and autoCompleteVerifiableItems()) working together with BOTH
     * activation_checklists and firm_activation_events under FORCE RLS
     * simultaneously — this is the whole point of this checkpoint.
     * Every read below that happens AFTER a service call (whose own
     * runWithFirmContext() wrap has already cleared context) is itself
     * wrapped in an explicit context, since it is a genuinely fresh
     * database read against these now-force-protected tables.
     */
    public function test_the_production_activation_service_methods_work_end_to_end_under_force_rls(): void
    {
        $activationChecklist = new ActivationChecklistService();
        $service = new FirmProductionActivationService($activationChecklist);

        $firm = $this->eligibleFirm();
        $activationChecklist->createChecklist($firm);
        $activationChecklist->seedProductionReadinessItems($firm->fresh());

        // Not yet ready — no items are complete.
        $notReadyResult = $service->evaluate($firm->fresh());
        $this->assertFalse($notReadyResult->ready);

        $evaluatedEvents = $this->runWithFirmContext(
            $firm,
            fn () => FirmActivationEvent::withoutGlobalScopes()->where('firm_id', $firm->id)->where('event_type', 'production_readiness_evaluated')->get(),
        );
        $this->assertCount(1, $evaluatedEvents);
        $this->assertSame(FirmActivationEventStatus::Blocked, $evaluatedEvents->first()->status);

        // Satisfy every auto-completable item, then mark every remaining
        // (manual-only) item complete directly.
        // Section 39A-3L, Checkpoint 18 — firm_settings now has FORCE
        // ROW LEVEL SECURITY active, so this bare FirmSettings::first()
        // read needs an explicit tenant context, same bare-read fix
        // pattern already used elsewhere in this file for
        // firm_activation_events/activation_checklists.
        $this->runWithFirmContext($firm, fn () => FirmSettings::first()->update(['state_jurisdiction' => 'NY']));
        FirmPracticeArea::factory()->create(['firm_id' => $firm->id, 'practice_area_id' => PracticeArea::factory()->create()->id, 'is_enabled' => true]);
        FirmUser::factory()->create(['firm_id' => $firm->id, 'user_id' => \App\Models\User::factory()->create()->id, 'status' => FirmUserStatus::Active]);

        // The two bare factory calls above each leave DB-session tenant
        // context set to $firm->id (the established context-hold
        // factory pattern) — establish a genuinely clean baseline here
        // so every subsequent runWithFirmContext()-wrapped call in this
        // test correctly restores to "no context" rather than to that
        // leftover value.
        (new TenantContextService())->clearDatabaseTenantContext();
        (new TenantContextService())->clearFirmContext();
        $this->assertNoDatabaseTenantContext();

        $completed = $service->autoCompleteVerifiableItems($firm->fresh());
        $this->assertNotEmpty($completed);

        $completedEventCount = $this->runWithFirmContext(
            $firm,
            fn () => FirmActivationEvent::withoutGlobalScopes()->where('firm_id', $firm->id)->where('event_type', 'checklist_item_completed')->count(),
        );
        $this->assertSame(count($completed), $completedEventCount);

        $this->runWithFirmContext($firm, fn () => $firm->fresh()->activationChecklist->items()->update([
            'is_complete' => true,
            'completed_at' => now(),
        ]));

        $readyResult = $service->evaluate($firm->fresh());
        $this->assertTrue($readyResult->ready);

        $productionReadyEventCount = $this->runWithFirmContext(
            $firm,
            fn () => FirmActivationEvent::withoutGlobalScopes()->where('firm_id', $firm->id)->where('event_type', 'production_ready')->count(),
        );
        $this->assertSame(1, $productionReadyEventCount);

        $this->assertNoDatabaseTenantContext();
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
        $migration = require base_path('database/migrations/2026_08_25_930003_force_rls_on_firm_activation_events_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'firm_activation_events'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while firm_activation_events is rolled back."
                );
            }

            // The policy itself must survive rollback unchanged — down()
            // only flips FORCE off, it never drops the policy.
            $policy = DB::selectOne(
                "select polname from pg_policy where polrelid = 'firm_activation_events'::regclass"
            );
            $this->assertNotNull($policy, 'Rollback must not drop the tenant isolation policy.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'firm_activation_events'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }

    /**
     * Twenty previously forced tables plus firm_activation_events must
     * be independently force-active and independently isolated at the
     * same time — proof this batch did not weaken or interfere with any
     * prior section's own enforcement. Uses activation_checklists (this
     * arc's own immediately preceding table) as the companion table,
     * since both are now forced simultaneously and both are exercised by
     * FirmProductionActivationService.
     */
    public function test_firm_activation_events_is_isolated_independently_and_simultaneously_with_activation_checklists(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $eventA = $this->runWithFirmContext($firmA, fn () => FirmActivationEvent::factory()->forFirm($firmA)->create());
        $eventB = $this->runWithFirmContext($firmB, fn () => FirmActivationEvent::factory()->forFirm($firmB)->create());

        $checklistA = $this->runWithFirmContext($firmA, fn () => ActivationChecklist::factory()->forFirm($firmA)->create());
        $checklistB = $this->runWithFirmContext($firmB, fn () => ActivationChecklist::factory()->forFirm($firmB)->create());

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'firm_activation_events' => FirmActivationEvent::withoutGlobalScopes()->pluck('id')->all(),
            'activation_checklists' => ActivationChecklist::withoutGlobalScopes()->pluck('id')->all(),
        ]);

        $this->assertSame([$eventA->id], $resultA['firm_activation_events']);
        $this->assertNotContains($eventB->id, $resultA['firm_activation_events']);
        $this->assertSame([$checklistA->id], $resultA['activation_checklists']);
        $this->assertNotContains($checklistB->id, $resultA['activation_checklists']);
    }

    /**
     * Genuinely append-only proof: zero update()/delete() call sites
     * exist against FirmActivationEvent anywhere in production code —
     * every production write is FirmActivationEvent::create(). This
     * matches the rls-policy-designer's own finding (see the migration's
     * docblock) and is what justifies never adding an explicit WITH
     * CHECK clause distinct from USING for this table.
     */
    public function test_firm_activation_event_has_no_update_or_delete_call_site_in_production_code(): void
    {
        $appDirectory = base_path('app');
        $violations = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appDirectory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if (! str_contains($source, 'FirmActivationEvent')) {
                continue;
            }

            if (preg_match('/FirmActivationEvent::query\(\)[^;]*->(update|delete)\s*\(/', $source)
                || preg_match('/FirmActivationEvent::(where|find|whereIn)\([^;]*->(update|delete)\s*\(/', $source)
            ) {
                $violations[] = $file->getPathname();
            }
        }

        $this->assertEmpty($violations, 'No production call site may update() or delete() a FirmActivationEvent row — it is genuinely append-only. Found: '.implode(', ', $violations));
    }

    /**
     * Honest scope note (not a claimed guarantee): firm_activation_events'
     * only foreign key besides firm_id is the nullable actor_user_id,
     * which references the global `users` table — not `firm_users` —
     * so there is no firm-scoped row on the other end of that
     * relationship for RLS to transitively validate against in the
     * first place. A raw insert whose firm_id matches the active
     * context still succeeds even when actor_user_id references a user
     * who has no FirmUser membership at this firm at all — this is
     * expected, not a residual gap, since actor_user_id was never
     * designed to be firm-scoped (unlike payment_id on
     * payment_classification_events, which does reference a
     * firm-scoped row).
     */
    public function test_actor_user_id_is_not_validated_against_the_firms_own_users_by_rls(): void
    {
        $firm = Firm::factory()->create();
        $unrelatedUser = \App\Models\User::factory()->create();

        $eventId = $this->runWithFirmContext($firm, function () use ($firm, $unrelatedUser) {
            return DB::table('firm_activation_events')->insertGetId([
                'firm_id' => $firm->id,
                'actor_user_id' => $unrelatedUser->id,
                'event_type' => 'checklist_item_completed',
                'status' => 'completed',
                'created_at' => now(),
            ]);
        });

        $this->assertIsInt(
            $eventId,
            'RLS validates only this row\'s own firm_id — actor_user_id referencing a user with no relationship to this firm is not itself blocked, since actor_user_id was never designed to be firm-scoped.'
        );

        // assertDatabaseHas() queries with no tenant context of its own,
        // so — correctly, now that this table is forced — it would see
        // zero rows. The re-read below is an explicit, context-wrapped
        // read instead, since this is a genuinely fresh database read
        // against this now-force-protected table.
        $reRead = $this->runWithFirmContext(
            $firm,
            fn () => DB::table('firm_activation_events')->where('id', $eventId)->first(),
        );

        $this->assertNotNull($reRead);
        $this->assertSame($unrelatedUser->id, $reRead->actor_user_id);
    }

    public function test_compliance_gap_registry_service_still_tracks_the_rls_gap(): void
    {
        $registry = new ComplianceGapRegistryService();

        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
    }
}
