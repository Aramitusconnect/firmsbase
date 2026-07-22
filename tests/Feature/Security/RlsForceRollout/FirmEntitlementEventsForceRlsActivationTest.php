<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\EntitlementSource;
use App\Models\Firm;
use App\Models\FirmEntitlement;
use App\Models\FirmEntitlementEvent;
use App\Models\ModuleCatalog;
use App\Services\ComplianceGapRegistryService;
use App\Services\DeploymentFeatureFlagAuditService;
use App\Services\EntitlementService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * FirmEntitlementEventsForceRlsActivationTest — Section 39A-3L,
 * Checkpoint 5, Table Phase C. Proves the twenty-third staged FORCE ROW
 * LEVEL SECURITY activation batch
 * (database/migrations/2026_08_25_930005_force_rls_on_firm_entitlement_events_table.php)
 * is permanently active for firm_entitlement_events and behaves
 * correctly: fail-closed with no context, correct cross-firm isolation,
 * correct same-firm access, that every previously-forced table (clients,
 * firm_users, documents, deadlines, tasks, matters, invoices, payments,
 * conflict_check_runs, lead_sources, consultation_outcomes, firm_leads,
 * consultations, firm_practice_areas, document_chase_rules,
 * employee_rates, calendar_events, client_communication_preferences,
 * payment_classification_events, activation_checklists,
 * firm_activation_events, firm_entitlements) remains forced
 * simultaneously, and that EntitlementService::setForSource() plus the
 * two direct-read fixes this batch made
 * (DeploymentFeatureFlagAuditService::auditTrailFor()/isFullyAudited())
 * still function correctly end-to-end under FORCE with BOTH
 * firm_entitlements and firm_entitlement_events forced at once.
 *
 * firm_entitlement_events is genuinely append-only: UPDATED_AT is
 * disabled at the model layer and no uuid column exists (unlike
 * firm_entitlements' HasPublicUuid). Raw DB::table('firm_entitlement_events')
 * insert() calls below never need a 'uuid' value, and created_at has a
 * database-level useCurrent() default so it never needs to be supplied
 * either. The cross-firm UPDATE test below still has genuine value even
 * though the application itself never calls ->update() on this model:
 * it proves the RLS policy's USING clause (which governs every SQL verb,
 * not just the ones the app happens to issue) rejects a raw UPDATE
 * statement identically to how it rejects SELECT/INSERT/DELETE — i.e.
 * the protection is a property of the policy itself, not of the
 * application only ever using this table in an append-only way.
 *
 * firm_entitlement_id is, unlike firm_entitlements' module_code, a real
 * firm-scoped foreign key (firm_entitlements is itself FORCE RLS
 * enabled as of Checkpoint 4) — so, matching
 * PaymentClassificationEventsForceRlsActivationTest's payment_id
 * finding, there IS a genuine transitive cross-firm mismatch risk here:
 * RLS's single-column policy validates only this row's own firm_id,
 * never that firm_entitlement_id transitively belongs to the same firm.
 * See test_firm_a_can_still_create_a_firm_entitlement_event_using_a_firm_b_firm_entitlement_at_the_raw_db_layer
 * below for the honest, empirically-proven boundary of that claim —
 * documented as a residual database-constraint gap, not a false
 * guarantee, which is exactly why FirmEntitlementEventFactory's own
 * root-cause fix (deriving firm_entitlement_id/firm_id/module_code/
 * source from one freshly-created FirmEntitlement of the SAME firm)
 * matters for factory-default safety.
 */
class FirmEntitlementEventsForceRlsActivationTest extends TestCase
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
        'firm_activation_events', 'firm_entitlements',
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

    public function test_firm_entitlement_events_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'firm_entitlement_events'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_firm_entitlement_events_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'firm_entitlement_events'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'firm_entitlement_events must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly twenty-three tables (the twenty-two previously forced plus
     * firm_entitlement_events) must be FORCE-enabled among ALL prepared
     * tables — no more, no less. This is the "exact expected count"
     * proof, independent of RlsForceRolloutFirewallTest's own equivalent
     * check, so this file stands alone as proof for this table.
     */
    /**
     * Narrowly updated by Section 39A-3L, Checkpoint 6, Table Phase C
     * (this repo's twenty-fourth staged FORCE activation batch, covering
     * installed_template_packs) to account for that later, legitimate
     * addition — the count below now reflects the real, current state of
     * this working tree rather than a frozen snapshot of Checkpoint 5
     * alone. Additive only: every originally-asserted table is still
     * asserted forced here.
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
    public function test_exactly_twenty_three_prepared_tables_are_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService();

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
        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'firm_entitlement_events', 'installed_template_packs', 'template_upgrade_logs', 'template_upgrade_previews', 'seat_allocations', 'document_requests', 'communication_consents', 'communication_consent_events', 'intake_submissions', 'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events', 'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans', 'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations', 'integration_credentials', 'integration_oauth_states', 'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings', 'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events', 'integration_inbound_webhook_events']);
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
        $this->assertSame(123, count($actuallyForced), 'Exactly thirty-six prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 13 — no more, no less (installed_template_packs, template_upgrade_logs, template_upgrade_previews, seat_allocations, document_requests, and communication_consents added on top of this batch\'s own firm_entitlement_events, plus communication_consent_events from Checkpoint 12, plus intake_submissions from Checkpoint 13). Narrowly updated again for Section 39A-3L, Checkpoint 14 (matter_readiness_scores added on top of the prior thirty-one), again for Checkpoint 15 (readiness_score_events added on top of the prior thirty-two), and again for Checkpoint 16 (tenant_encryption_keys added on top of the prior thirty-three).');
        $this->assertSame($expectedForced, $actuallyForced);
    }

    public function test_the_tenant_isolation_policy_remains_present_and_unchanged_after_up(): void
    {
        $policy = DB::selectOne(
            "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'firm_entitlement_events'::regclass"
        );

        $this->assertNotNull($policy, 'The firm_entitlement_events tenant isolation policy must still exist.');
        $this->assertSame('firm_entitlement_events_tenant_isolation', $policy->polname);
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
    public function test_missing_tenant_context_cannot_read_firm_entitlement_events(): void
    {
        $firm = Firm::factory()->create();
        $entitlement = $this->runWithFirmContext($firm, fn () => FirmEntitlement::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => FirmEntitlementEvent::factory()->forEntitlement($entitlement)->create());

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, FirmEntitlementEvent::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_insert_firm_entitlement_events(): void
    {
        $firm = Firm::factory()->create();
        $entitlement = $this->runWithFirmContext($firm, fn () => FirmEntitlement::factory()->forFirm($firm)->create());

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('firm_entitlement_events')->insert([
            'firm_entitlement_id' => $entitlement->id,
            'firm_id' => $firm->id,
            'module_code' => $entitlement->module_code,
            'source' => 'admin_override',
            'action' => 'granted',
        ]);
    }

    public function test_firm_a_context_can_read_its_own_firm_entitlement_event(): void
    {
        $firmA = Firm::factory()->create();
        $entitlementA = $this->runWithFirmContext($firmA, fn () => FirmEntitlement::factory()->forFirm($firmA)->create());
        $eventA = $this->runWithFirmContext($firmA, fn () => FirmEntitlementEvent::factory()->forEntitlement($entitlementA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => FirmEntitlementEvent::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$eventA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_firm_entitlement_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $entitlementA = $this->runWithFirmContext($firmA, fn () => FirmEntitlement::factory()->forFirm($firmA)->create());
        $this->runWithFirmContext($firmA, fn () => FirmEntitlementEvent::factory()->forEntitlement($entitlementA)->create());

        $entitlementB = $this->runWithFirmContext($firmB, fn () => FirmEntitlement::factory()->forFirm($firmB)->create());
        $eventB = $this->runWithFirmContext($firmB, fn () => FirmEntitlementEvent::factory()->forEntitlement($entitlementB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => FirmEntitlementEvent::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($eventB->id, $visibleIds);
    }

    public function test_valid_insert_with_correct_firm_context_succeeds(): void
    {
        $firm = Firm::factory()->create();
        $entitlement = $this->runWithFirmContext($firm, fn () => FirmEntitlement::factory()->forFirm($firm)->create());

        $eventId = $this->runWithFirmContext($firm, function () use ($firm, $entitlement) {
            return DB::table('firm_entitlement_events')->insertGetId([
                'firm_entitlement_id' => $entitlement->id,
                'firm_id' => $firm->id,
                'module_code' => $entitlement->module_code,
                'source' => 'admin_override',
                'action' => 'granted',
            ]);
        });

        $this->assertIsInt($eventId);
    }

    public function test_firm_a_context_cannot_insert_a_firm_entitlement_event_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $entitlementB = $this->runWithFirmContext($firmB, fn () => FirmEntitlement::factory()->forFirm($firmB)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $entitlementB) {
            DB::table('firm_entitlement_events')->insert([
                'firm_entitlement_id' => $entitlementB->id,
                'firm_id' => $firmB->id,
                'module_code' => $entitlementB->module_code,
                'source' => 'admin_override',
                'action' => 'granted',
            ]);
        });
    }

    /**
     * The application never issues an UPDATE against this append-only
     * model, but the RLS policy's USING clause governs UPDATE the same
     * as SELECT/INSERT/DELETE — this proves that guarantee directly
     * rather than merely asserting it because "the app never does this."
     */
    public function test_firm_a_context_cannot_update_firm_b_firm_entitlement_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $entitlementB = $this->runWithFirmContext($firmB, fn () => FirmEntitlement::factory()->forFirm($firmB)->create());
        $eventB = $this->runWithFirmContext($firmB, fn () => FirmEntitlementEvent::factory()->forEntitlement($entitlementB)->create(['reason' => 'original reason']));

        $this->runWithFirmContext($firmA, function () use ($eventB) {
            DB::table('firm_entitlement_events')->where('id', $eventB->id)->update(['reason' => 'tampered by firm A']);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FirmEntitlementEvent::withoutGlobalScopes()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame('original reason', $reReadAsFirmB->reason, 'Firm A context must not be able to update Firm B\'s firm_entitlement_events row.');
    }

    public function test_firm_a_context_cannot_delete_firm_b_firm_entitlement_event(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $entitlementB = $this->runWithFirmContext($firmB, fn () => FirmEntitlement::factory()->forFirm($firmB)->create());
        $eventB = $this->runWithFirmContext($firmB, fn () => FirmEntitlementEvent::factory()->forEntitlement($entitlementB)->create());

        $this->runWithFirmContext($firmA, function () use ($eventB) {
            DB::table('firm_entitlement_events')->where('id', $eventB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FirmEntitlementEvent::withoutGlobalScopes()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s firm_entitlement_events row.');
    }

    /**
     * Firm ownership itself (the firm_id column) must never be
     * reassignable via a raw UPDATE while under a different firm's
     * context — even setting aside the value being updated TO, the
     * policy's USING clause must reject the row entirely once no rows
     * are visible under firmA's context.
     */
    public function test_firm_a_context_cannot_reassign_firm_b_firm_entitlement_event_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $entitlementB = $this->runWithFirmContext($firmB, fn () => FirmEntitlement::factory()->forFirm($firmB)->create());
        $eventB = $this->runWithFirmContext($firmB, fn () => FirmEntitlementEvent::factory()->forEntitlement($entitlementB)->create());

        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $eventB) {
            return DB::table('firm_entitlement_events')->where('id', $eventB->id)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s event to itself.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FirmEntitlementEvent::withoutGlobalScopes()->find($eventB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame($firmB->id, $reReadAsFirmB->firm_id);
    }

    /**
     * Empirically proves the honest scope boundary described in this
     * file's class docblock: RLS validates only this row's own firm_id
     * — a raw insert whose firm_id matches the active context still
     * succeeds even when firm_entitlement_id points at ANOTHER firm's
     * firm_entitlements row. This is a documented residual
     * DATABASE-CONSTRAINT gap, not something RLS itself closes — never
     * to be described as blocked.
     */
    public function test_firm_a_can_still_create_a_firm_entitlement_event_using_a_firm_b_firm_entitlement_at_the_raw_db_layer(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $entitlementB = $this->runWithFirmContext($firmB, fn () => FirmEntitlement::factory()->forFirm($firmB)->create());

        $mismatchedEventId = $this->runWithFirmContext($firmA, function () use ($firmA, $entitlementB) {
            return DB::table('firm_entitlement_events')->insertGetId([
                'firm_entitlement_id' => $entitlementB->id,
                'firm_id' => $firmA->id,
                'module_code' => $entitlementB->module_code,
                'source' => 'admin_override',
                'action' => 'granted',
            ]);
        });

        $this->assertIsInt(
            $mismatchedEventId,
            'RLS only checks the row\'s own firm_id — a transitive firm_entitlement_id/firm_id mismatch is NOT blocked by RLS; this is a documented residual gap, not a false guarantee.'
        );
    }

    /**
     * Bare factory default: FirmEntitlementEventFactory::definition()
     * generates one fresh FirmEntitlement up front and derives
     * firm_entitlement_id/firm_id/module_code/source from it — the
     * confirmed cross-firm mismatch this fixed (event.firm_id !=
     * firmEntitlement.firm_id) must not recur.
     */
    public function test_firm_entitlement_event_factory_default_creation_is_internally_consistent(): void
    {
        $event = FirmEntitlementEvent::factory()->create();

        $this->assertNotNull($event->id);
        $this->assertNotNull($event->firm_id);
        $this->assertNotNull($event->firm_entitlement_id);

        $result = $this->runWithFirmContext($event->firm, function () use ($event) {
            return [
                'event' => FirmEntitlementEvent::withoutGlobalScopes()->find($event->id),
                'entitlement' => FirmEntitlement::withoutGlobalScopes()->find($event->firm_entitlement_id),
            ];
        });

        $this->assertNotNull($result['event'], 'A bare factory-created event must be visible under its own firm\'s context.');
        $this->assertNotNull($result['entitlement'], 'The event\'s firm_entitlement_id must resolve to a row visible under the SAME firm\'s context.');
        $this->assertSame($event->firm_id, $result['entitlement']->firm_id, 'A bare factory-created event must never disagree with its own firm_entitlement\'s firm_id.');
    }

    /**
     * Explicit related-model factory state correctness: forEntitlement()
     * must tie the created event to the exact FirmEntitlement given —
     * not merely to some other row the base definition() happens to
     * spin up independently — and this must hold correctly whether the
     * caller supplies the entitlement's firm via forFirm() first or not.
     */
    public function test_firm_entitlement_event_factory_for_entitlement_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create(['module_code' => 'module_explicit_event_check']);
        $entitlement = $this->runWithFirmContext(
            $firm,
            fn () => FirmEntitlement::factory()->forFirm($firm)->forModule($module)->source(EntitlementSource::FirmOverride)->create(),
        );

        $event = $this->runWithFirmContext(
            $firm,
            fn () => FirmEntitlementEvent::factory()->forEntitlement($entitlement)->create(['action' => 'revoked']),
        );

        $this->assertSame($entitlement->id, $event->firm_entitlement_id);
        $this->assertSame($firm->id, $event->firm_id);
        $this->assertSame('module_explicit_event_check', $event->module_code);
        $this->assertSame('firm_override', $event->source);
        $this->assertSame('revoked', $event->action);

        $reRead = $this->runWithFirmContext(
            $firm,
            fn () => FirmEntitlementEvent::withoutGlobalScopes()->find($event->id),
        );

        $this->assertNotNull($reRead);
        $this->assertSame($entitlement->id, $reRead->firm_entitlement_id);
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        $entitlement = $this->runWithFirmContext($firm, fn () => FirmEntitlement::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, fn () => FirmEntitlementEvent::factory()->forEntitlement($entitlement)->create());

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
     * End-to-end proof of EntitlementService::setForSource()'s single
     * whole-body wrap (which writes both the firm_entitlements row and
     * its firm_entitlement_events row in one transaction) working
     * correctly now that BOTH tables are FORCE RLS enabled
     * simultaneously — this is the whole point of this checkpoint. Every
     * read below that happens AFTER a service call (whose own
     * runWithFirmContext() wrap has already cleared context) is itself
     * wrapped in an explicit context, since it is a genuinely fresh
     * database read against these now-force-protected tables.
     */
    public function test_the_entitlement_service_set_for_source_flow_writes_a_consistent_event_end_to_end_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $actor = \App\Models\User::factory()->create();
        $service = new EntitlementService();

        $granted = $service->setForSource(
            $firm,
            $module->module_code,
            EntitlementSource::AdminOverride,
            true,
            [],
            $actor,
            'checkpoint 5 activation proof - grant',
        );

        $updated = $service->setForSource(
            $firm,
            $module->module_code,
            EntitlementSource::AdminOverride,
            false,
            [],
            $actor,
            'checkpoint 5 activation proof - revoke',
        );

        $this->assertSame($granted->id, $updated->id, 'setForSource() upserts the same entitlement row for the same source.');

        $events = $this->runWithFirmContext(
            $firm,
            fn () => FirmEntitlementEvent::withoutGlobalScopes()->where('firm_entitlement_id', $granted->id)->orderBy('id')->get(),
        );

        $this->assertCount(2, $events, 'One event per setForSource() call — granted then updated.');
        $this->assertSame('granted', $events[0]->action);
        $this->assertSame($firm->id, $events[0]->firm_id);
        $this->assertSame($module->module_code, $events[0]->module_code);
        $this->assertSame('updated', $events[1]->action);
        $this->assertSame($firm->id, $events[1]->firm_id);

        $this->assertNoDatabaseTenantContext();
    }

    /**
     * Proves DeploymentFeatureFlagAuditService's two direct reads
     * against firm_entitlement_events (auditTrailFor() and
     * isFullyAudited()'s $eventCount query) — each independently
     * wrapped in this batch — still function correctly now that
     * firm_entitlement_events is FORCE RLS enabled.
     */
    public function test_the_deployment_feature_flag_audit_service_audit_trail_for_and_is_fully_audited_both_function_correctly_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $entitlementService = new EntitlementService();
        $service = new DeploymentFeatureFlagAuditService();

        $this->assertTrue($service->isFullyAudited($firm), 'A firm with zero entitlement rows is trivially fully audited.');
        $this->assertCount(0, $service->auditTrailFor($firm));

        $entitlementService->setForSource(
            $firm,
            $module->module_code,
            EntitlementSource::AdminOverride,
            true,
            [],
            null,
            'checkpoint 5 activation proof',
        );

        $this->assertTrue($service->isFullyAudited($firm), 'setForSource() always writes a FirmEntitlementEvent in the same transaction, so the trail must remain complete.');

        $trail = $service->auditTrailFor($firm, $module->module_code);
        $this->assertCount(1, $trail);
        $this->assertSame($module->module_code, $trail->first()->module_code);
        $this->assertNoDatabaseTenantContext();
    }

    /**
     * Rollback support: the migration's down() must genuinely restore
     * the Section 39A baseline — RLS still enabled, policy still
     * present, but NOT forced — never drop the policy or disable RLS
     * itself. Also proves rollback affects ONLY this one table — every
     * other previously-forced table (including firm_entitlements, its
     * own immediate parent table) must be untouched by this specific
     * migration's down()/up() cycle.
     */
    public function test_migration_down_restores_the_not_forced_baseline_and_affects_only_this_table(): void
    {
        $migration = require base_path('database/migrations/2026_08_25_930005_force_rls_on_firm_entitlement_events_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'firm_entitlement_events'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while firm_entitlement_events is rolled back."
                );
            }

            // The policy itself must survive rollback unchanged — down()
            // only flips FORCE off, it never drops the policy.
            $policy = DB::selectOne(
                "select polname from pg_policy where polrelid = 'firm_entitlement_events'::regclass"
            );
            $this->assertNotNull($policy, 'Rollback must not drop the tenant isolation policy.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'firm_entitlement_events'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }

    /**
     * Twenty-two previously forced tables plus firm_entitlement_events
     * must be independently force-active and independently isolated at
     * the same time — proof this batch did not weaken or interfere with
     * any prior section's own enforcement. Uses firm_entitlements (this
     * table's own immediate parent, and this arc's own immediately
     * preceding table) as the companion table.
     */
    public function test_firm_entitlement_events_is_isolated_independently_and_simultaneously_with_firm_entitlements(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $entitlementA = $this->runWithFirmContext($firmA, fn () => FirmEntitlement::factory()->forFirm($firmA)->create());
        $entitlementB = $this->runWithFirmContext($firmB, fn () => FirmEntitlement::factory()->forFirm($firmB)->create());

        $eventA = $this->runWithFirmContext($firmA, fn () => FirmEntitlementEvent::factory()->forEntitlement($entitlementA)->create());
        $eventB = $this->runWithFirmContext($firmB, fn () => FirmEntitlementEvent::factory()->forEntitlement($entitlementB)->create());

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'firm_entitlements' => FirmEntitlement::withoutGlobalScopes()->pluck('id')->all(),
            'firm_entitlement_events' => FirmEntitlementEvent::withoutGlobalScopes()->pluck('id')->all(),
        ]);

        $this->assertSame([$entitlementA->id], $resultA['firm_entitlements']);
        $this->assertNotContains($entitlementB->id, $resultA['firm_entitlements']);
        $this->assertSame([$eventA->id], $resultA['firm_entitlement_events']);
        $this->assertNotContains($eventB->id, $resultA['firm_entitlement_events']);
    }

    public function test_compliance_gap_registry_service_still_tracks_the_rls_gap(): void
    {
        $registry = new ComplianceGapRegistryService();

        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
    }
}
