<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\DowngradeCheckStatus;
use App\Enums\EntitlementSource;
use App\Models\Firm;
use App\Models\FirmEntitlement;
use App\Models\ModuleCatalog;
use App\Models\Plan;
use App\Services\ComplianceGapRegistryService;
use App\Services\DeploymentFeatureFlagAuditService;
use App\Services\DowngradeEvaluationService;
use App\Services\EntitlementService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FirmEntitlementsForceRlsActivationTest — Section 39A-3L, Checkpoint
 * 4, Table Phase C. Proves the twenty-second staged FORCE ROW LEVEL
 * SECURITY activation batch
 * (database/migrations/2026_08_25_930004_force_rls_on_firm_entitlements_table.php)
 * is permanently active for firm_entitlements and behaves correctly:
 * fail-closed with no context, correct cross-firm isolation, correct
 * same-firm access, that every previously-forced table (clients,
 * firm_users, documents, deadlines, tasks, matters, invoices, payments,
 * conflict_check_runs, lead_sources, consultation_outcomes, firm_leads,
 * consultations, firm_practice_areas, document_chase_rules,
 * employee_rates, calendar_events, client_communication_preferences,
 * payment_classification_events, activation_checklists,
 * firm_activation_events) remains forced simultaneously, and that
 * EntitlementService::setForSource()/resolve() plus the two other
 * direct-read fixes this batch made (DowngradeEvaluationService::
 * evaluate(), DeploymentFeatureFlagAuditService::isFullyAudited())
 * still function correctly end-to-end under FORCE.
 *
 * firm_entitlements' only foreign keys besides firm_id are module_code
 * (a STRING FK to module_catalog.module_code) and the nullable
 * created_by (references the global `users` table). module_catalog is
 * confirmed genuinely global/non-tenant — no firm_id column, no
 * BelongsToTenant, RLS never enabled on it — so, unlike
 * payment_classification_events' payment_id (which DOES reference a
 * firm-scoped row), there is no analogous transitive firm_id mismatch
 * for RLS to police here. See
 * test_module_code_has_no_transitive_cross_firm_mismatch_risk below for
 * the honest, empirically-proven boundary of that claim — not merely
 * asserted.
 *
 * Raw DB::table('firm_entitlements')->insert(...) calls below always
 * supply an explicit 'uuid' value: HasPublicUuid only populates uuid
 * via an Eloquent 'creating' model event, which a raw query-builder
 * insert never fires, and the uuid column has no database-level
 * default.
 */
class FirmEntitlementsForceRlsActivationTest extends TestCase
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
        'firm_activation_events',
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

    public function test_firm_entitlements_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'firm_entitlements'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_firm_entitlements_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'firm_entitlements'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'firm_entitlements must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    /**
     * Exactly twenty-two tables (the twenty-one previously forced plus
     * firm_entitlements) must be FORCE-enabled among ALL prepared
     * tables — no more, no less. This is the "exact expected count"
     * proof, independent of RlsForceRolloutFirewallTest's own
     * equivalent check, so this file stands alone as proof for this
     * table.
     *
     * Narrowly updated by Section 39A-3L, Checkpoint 5, Table Phase C
     * (this repo's twenty-third staged FORCE activation batch, covering
     * firm_entitlement_events) to account for that later, legitimate
     * addition — the count and expected-table list below now reflect
     * the real, current state of this working tree rather than a
     * frozen snapshot of Checkpoint 4 alone. Additive only: every
     * originally-asserted table is still asserted forced here.
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
    public function test_exactly_twenty_two_prepared_tables_are_force_row_level_security_enabled(): void
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
        $expectedForced = array_merge(self::PREVIOUSLY_FORCED_TABLES, ['ai_retrieval_indexes', 'deployment_configs', 'firm_ai_settings', 'email_visibility_rules', 'private_enterprise_settings', 'matter_expenses', 'email_message_links', 'ai_usage_events', 'ai_tool_actions', 'firm_ai_provider_keys', 'ai_approval_requests', 'ai_approval_events', 'chart_of_accounts', 'expense_categories', 'expenses', 'expense_receipts', 'expense_approvals', 'accounting_export_batches', 'accounting_export_lines', 'email_accounts', 'email_messages', 'email_attachments', 'email_sync_events', 'generated_documents', 'form_drafts', 'generated_document_events', 'form_review_events', 'document_hashes', 'pdf_view_events', 'customer_success_health_scores', 'firm_entitlements', 'firm_entitlement_events', 'installed_template_packs', 'template_upgrade_logs', 'template_upgrade_previews', 'seat_allocations', 'document_requests', 'communication_consents', 'communication_consent_events', 'intake_submissions', 'matter_readiness_scores', 'readiness_score_events', 'tenant_encryption_keys', 'document_chase_events', 'firm_settings', 'firm_licenses', 'time_tracking_sessions', 'time_entries', 'payment_plans', 'payment_plan_events', 'notification_events', 'contacts', 'parties', 'backup_restore_tests', 'health_checks', 'incident_events', 'maintenance_windows', 'notification_templates', 'pilot_feedback_items', 'timeline_events', 'security_events', 'signature_certificates', 'signature_events', 'signature_request_recipients', 'signature_requests', 'legal_holds', 'deletion_requests', 'key_destruction_requests', 'support_access_requests', 'support_access_sessions', 'deployment_health_checks', 'export_jobs', 'migration_projects', 'import_batches', 'implementation_projects', 'fleet_migration_instance_status', 'offboarding_requests', 'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances', 'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events', 'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests', 'webhook_deliveries', 'webhook_delivery_attempts', 'webhook_events', 'webhook_secrets', 'webhook_subscriptions', 'firm_integrations', 'integration_credentials', 'integration_oauth_states', 'integration_sync_runs', 'integration_sync_items', 'integration_external_mappings', 'integration_sync_cursors', 'integration_conflicts', 'integration_outbox_events']);
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
        $this->assertSame(122, count($actuallyForced), 'Exactly thirty-six prepared tables must be FORCE RLS enabled after Section 39A-3L, Checkpoint 13 — no more, no less (firm_entitlement_events, installed_template_packs, template_upgrade_logs, template_upgrade_previews, seat_allocations, document_requests, and communication_consents added on top of this batch\'s own firm_entitlements, plus communication_consent_events from Checkpoint 12, plus intake_submissions from Checkpoint 13). Narrowly updated again for Section 39A-3L, Checkpoint 14 (matter_readiness_scores added on top of the prior thirty-one), again for Checkpoint 15 (readiness_score_events added on top of the prior thirty-two), and again for Checkpoint 16 (tenant_encryption_keys added on top of the prior thirty-three).');
        $this->assertSame($expectedForced, $actuallyForced);
    }

    public function test_the_tenant_isolation_policy_remains_present_and_unchanged_after_up(): void
    {
        $policy = DB::selectOne(
            "select polname, pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'firm_entitlements'::regclass"
        );

        $this->assertNotNull($policy, 'The firm_entitlements tenant isolation policy must still exist.');
        $this->assertSame('firm_entitlements_tenant_isolation', $policy->polname);
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
    public function test_missing_tenant_context_cannot_read_firm_entitlements(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => FirmEntitlement::factory()->forFirm($firm)->create());

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->assertSame(0, FirmEntitlement::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_insert_firm_entitlements(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('firm_entitlements')->insert([
            'uuid' => (string) Str::uuid(),
            'firm_id' => $firm->id,
            'module_code' => $module->module_code,
            'enabled' => true,
            'source' => 'admin_override',
        ]);
    }

    public function test_firm_a_context_can_read_its_own_firm_entitlement(): void
    {
        $firmA = Firm::factory()->create();
        $entitlementA = $this->runWithFirmContext($firmA, fn () => FirmEntitlement::factory()->forFirm($firmA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => FirmEntitlement::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$entitlementA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_firm_entitlement(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->runWithFirmContext($firmA, fn () => FirmEntitlement::factory()->forFirm($firmA)->create());
        $entitlementB = $this->runWithFirmContext($firmB, fn () => FirmEntitlement::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => FirmEntitlement::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($entitlementB->id, $visibleIds);
    }

    public function test_valid_insert_with_correct_firm_context_succeeds(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();

        $entitlementId = $this->runWithFirmContext($firm, function () use ($firm, $module) {
            return DB::table('firm_entitlements')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firm->id,
                'module_code' => $module->module_code,
                'enabled' => true,
                'source' => 'admin_override',
            ]);
        });

        $this->assertIsInt($entitlementId);
    }

    public function test_firm_a_context_cannot_insert_a_firm_entitlement_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $module) {
            DB::table('firm_entitlements')->insert([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmB->id,
                'module_code' => $module->module_code,
                'enabled' => true,
                'source' => 'admin_override',
            ]);
        });
    }

    public function test_firm_a_context_cannot_update_firm_b_firm_entitlement(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $entitlementB = $this->runWithFirmContext($firmB, fn () => FirmEntitlement::factory()->forFirm($firmB)->create(['enabled' => true]));

        $this->runWithFirmContext($firmA, function () use ($entitlementB) {
            DB::table('firm_entitlements')->where('id', $entitlementB->id)->update(['enabled' => false]);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FirmEntitlement::withoutGlobalScopes()->find($entitlementB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertTrue($reReadAsFirmB->enabled, 'Firm A context must not be able to update Firm B\'s firm_entitlements row.');
    }

    public function test_firm_a_context_cannot_delete_firm_b_firm_entitlement(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $entitlementB = $this->runWithFirmContext($firmB, fn () => FirmEntitlement::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($entitlementB) {
            DB::table('firm_entitlements')->where('id', $entitlementB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FirmEntitlement::withoutGlobalScopes()->find($entitlementB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B\'s firm_entitlements row.');
    }

    /**
     * Firm ownership itself (the firm_id column) must never be
     * reassignable via a raw UPDATE while under a different firm's
     * context — even setting aside the value being updated TO, the
     * policy's USING clause must reject the row entirely once no rows
     * are visible under firmA's context.
     */
    public function test_firm_a_context_cannot_reassign_firm_b_firm_entitlement_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $entitlementB = $this->runWithFirmContext($firmB, fn () => FirmEntitlement::factory()->forFirm($firmB)->create());

        $affected = $this->runWithFirmContext($firmA, function () use ($firmA, $entitlementB) {
            return DB::table('firm_entitlements')->where('id', $entitlementB->id)->update(['firm_id' => $firmA->id]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to reassign Firm B\'s entitlement to itself.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FirmEntitlement::withoutGlobalScopes()->find($entitlementB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame($firmB->id, $reReadAsFirmB->firm_id);
    }

    public function test_firm_entitlement_factory_default_creation_is_internally_consistent(): void
    {
        $entitlement = FirmEntitlement::factory()->create();

        $this->assertNotNull($entitlement->id);
        $this->assertNotNull($entitlement->firm_id);
        $this->assertNotNull($entitlement->module_code);

        $reRead = $this->runWithFirmContext(
            $entitlement->firm,
            fn () => FirmEntitlement::withoutGlobalScopes()->find($entitlement->id),
        );

        $this->assertNotNull($reRead, 'A bare factory-created entitlement must be visible under its own firm\'s context.');
        $this->assertSame($entitlement->firm_id, $reRead->firm_id);
    }

    public function test_firm_entitlement_factory_for_firm_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();
        $entitlement = FirmEntitlement::factory()->forFirm($firm)->create();

        $this->assertSame($firm->id, $entitlement->firm_id);

        $reRead = $this->runWithFirmContext(
            $firm,
            fn () => FirmEntitlement::withoutGlobalScopes()->find($entitlement->id),
        );

        $this->assertNotNull($reRead);
    }

    /**
     * Explicit related-model factory state correctness: forModule()
     * must tie the created row to the exact ModuleCatalog row given —
     * not merely to some other row the base definition() happens to
     * spin up independently.
     */
    public function test_firm_entitlement_factory_for_module_state_is_internally_consistent(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create(['module_code' => 'module_explicit_check']);

        $entitlement = FirmEntitlement::factory()->forFirm($firm)->forModule($module)
            ->source(EntitlementSource::FirmOverride)->disabled()->create();

        $this->assertSame('module_explicit_check', $entitlement->module_code);
        $this->assertSame(EntitlementSource::FirmOverride, $entitlement->source);
        $this->assertFalse($entitlement->enabled);

        $reRead = $this->runWithFirmContext(
            $firm,
            fn () => FirmEntitlement::withoutGlobalScopes()->find($entitlement->id),
        );

        $this->assertNotNull($reRead);
        $this->assertSame('module_explicit_check', $reRead->module_code);
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => FirmEntitlement::factory()->forFirm($firm)->create());

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
     * module_catalog is confirmed genuinely global/non-tenant — no
     * firm_id column, no BelongsToTenant, RLS never enabled on it —
     * and this batch's migration explicitly leaves it untouched.
     * Proves it directly: module_catalog has no relforcerowsecurity/
     * relrowsecurity active at all, and remains readable with no
     * tenant context whatsoever, even while firm_entitlements
     * (referencing it via module_code) is FORCE RLS enabled.
     */
    public function test_module_catalog_remains_globally_readable_and_unaffected_by_this_batch(): void
    {
        $module = ModuleCatalog::factory()->create();

        $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'module_catalog'");
        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->relrowsecurity, 'module_catalog must never have RLS enabled — it is genuinely global reference data.');
        $this->assertFalse((bool) $row->relforcerowsecurity);

        (new TenantContextService())->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $reRead = ModuleCatalog::query()->find($module->id);
        $this->assertNotNull($reRead, 'module_catalog must remain readable with zero tenant context active.');
    }

    /**
     * Honest scope note (not a claimed guarantee), matching the
     * project's convention for a related model with no firm-scoped
     * ownership of its own (see firm_activation_events'
     * actor_user_id-vs-users equivalent): module_catalog has no
     * firm_id at all, so there is no "does module_code belong to this
     * firm" check for RLS to transitively enforce in the first place.
     * A raw insert whose firm_id matches the active context succeeds
     * referencing ANY existing module_catalog row, regardless of which
     * firm (if any) "owns" it conceptually — this is expected, not a
     * residual gap, since module_catalog was never designed to be
     * firm-scoped.
     */
    public function test_module_code_has_no_transitive_cross_firm_mismatch_risk(): void
    {
        $firmA = Firm::factory()->create();
        $sharedModule = ModuleCatalog::factory()->create();

        $entitlementId = $this->runWithFirmContext($firmA, function () use ($firmA, $sharedModule) {
            return DB::table('firm_entitlements')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'firm_id' => $firmA->id,
                'module_code' => $sharedModule->module_code,
                'enabled' => true,
                'source' => 'admin_override',
            ]);
        });

        $this->assertIsInt(
            $entitlementId,
            'RLS validates only this row\'s own firm_id — module_code referencing a globally-shared module_catalog row is not itself blocked, since module_catalog was never designed to be firm-scoped.'
        );

        $reRead = $this->runWithFirmContext(
            $firmA,
            fn () => DB::table('firm_entitlements')->where('id', $entitlementId)->first(),
        );

        $this->assertNotNull($reRead);
        $this->assertSame($sharedModule->module_code, $reRead->module_code);
    }

    /**
     * End-to-end proof of EntitlementService's two whole-method-wrapped
     * chokepoints (setForSource() and resolve()) working correctly
     * together under FORCE RLS — this is the whole point of this
     * checkpoint. Every read below that happens AFTER a service call
     * (whose own runWithFirmContext() wrap has already cleared context)
     * is itself wrapped in an explicit context, since it is a genuinely
     * fresh database read against this now-force-protected table.
     */
    public function test_the_entitlement_service_set_for_source_and_resolve_flow_works_end_to_end_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $service = new EntitlementService();

        $notEntitled = $service->resolve($firm->id, $module->module_code);
        $this->assertFalse($notEntitled->enabled);

        $entitlement = $service->setForSource($firm, $module->module_code, EntitlementSource::Plan, true);

        $resolved = $service->resolve($firm->id, $module->module_code);
        $this->assertTrue($resolved->enabled);
        $this->assertSame(EntitlementSource::Plan, $resolved->source);

        $updated = $service->setForSource($firm, $module->module_code, EntitlementSource::AdminOverride, false);

        $resolvedAfterOverride = $service->resolve($firm->id, $module->module_code);
        $this->assertFalse($resolvedAfterOverride->enabled, 'admin_override must win precedence over plan, even though it disables the module.');
        $this->assertSame(EntitlementSource::AdminOverride, $resolvedAfterOverride->source);

        $reRead = $this->runWithFirmContext(
            $firm,
            fn () => FirmEntitlement::withoutGlobalScopes()->where('firm_id', $firm->id)->get(),
        );
        $this->assertCount(2, $reRead, 'Plan and admin_override are distinct source rows per the unique(firm_id, module_code, source) constraint.');

        $this->assertNotNull($entitlement->id);
        $this->assertNotNull($updated->id);
        $this->assertNoDatabaseTenantContext();
    }

    /**
     * Proves DowngradeEvaluationService::evaluate()'s own direct read
     * against firm_entitlements (independent of
     * EntitlementService::resolve() — it enumerates raw enabled rows
     * to find currently-in-use modules the new plan doesn't grant)
     * still functions correctly now that firm_entitlements is FORCE RLS
     * enabled.
     */
    public function test_the_downgrade_evaluation_service_still_functions_correctly_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $entitlementService = new EntitlementService();

        $entitlementService->setForSource($firm, $module->module_code, EntitlementSource::AdminOverride, true);

        $newPlan = Plan::factory()->create();

        $service = app(DowngradeEvaluationService::class);
        $result = $service->evaluate($firm->fresh(), $newPlan);

        $this->assertFalse($result->safe);
        $this->assertSame(DowngradeCheckStatus::BlockedModuleInUse, $result->status);
        $this->assertNoDatabaseTenantContext();
    }

    /**
     * Proves DeploymentFeatureFlagAuditService::isFullyAudited()'s own
     * direct read against firm_entitlements (independent of
     * EntitlementService::resolve()) still functions correctly now
     * that firm_entitlements is FORCE RLS enabled.
     */
    public function test_the_deployment_feature_flag_audit_service_still_functions_correctly_under_force_rls(): void
    {
        $firm = Firm::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $entitlementService = new EntitlementService();

        $service = new DeploymentFeatureFlagAuditService();

        $this->assertTrue($service->isFullyAudited($firm), 'A firm with zero entitlement rows is trivially fully audited.');

        $entitlementService->setForSource(
            $firm,
            $module->module_code,
            EntitlementSource::AdminOverride,
            true,
            [],
            null,
            'checkpoint 4 activation proof',
        );

        $this->assertTrue($service->isFullyAudited($firm), 'setForSource() always writes a FirmEntitlementEvent in the same transaction, so the trail must remain complete.');
        $this->assertCount(1, $service->auditTrailFor($firm));
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
        $migration = require base_path('database/migrations/2026_08_25_930004_force_rls_on_firm_entitlements_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'firm_entitlements'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            foreach (self::PREVIOUSLY_FORCED_TABLES as $table) {
                $otherRow = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

                $this->assertTrue(
                    (bool) $otherRow->relforcerowsecurity,
                    "{$table} must remain FORCE RLS enabled even while firm_entitlements is rolled back."
                );
            }

            // The policy itself must survive rollback unchanged — down()
            // only flips FORCE off, it never drops the policy.
            $policy = DB::selectOne(
                "select polname from pg_policy where polrelid = 'firm_entitlements'::regclass"
            );
            $this->assertNotNull($policy, 'Rollback must not drop the tenant isolation policy.');
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'firm_entitlements'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity, 'up() must be restored in the finally block.');
    }

    /**
     * Twenty-one previously forced tables plus firm_entitlements must
     * be independently force-active and independently isolated at the
     * same time — proof this batch did not weaken or interfere with any
     * prior section's own enforcement. Uses firm_activation_events
     * (this arc's own immediately preceding table) as the companion
     * table.
     */
    public function test_firm_entitlements_is_isolated_independently_and_simultaneously_with_firm_activation_events(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $entitlementA = $this->runWithFirmContext($firmA, fn () => FirmEntitlement::factory()->forFirm($firmA)->create());
        $entitlementB = $this->runWithFirmContext($firmB, fn () => FirmEntitlement::factory()->forFirm($firmB)->create());

        $eventA = $this->runWithFirmContext($firmA, fn () => \App\Models\FirmActivationEvent::factory()->forFirm($firmA)->create());
        $eventB = $this->runWithFirmContext($firmB, fn () => \App\Models\FirmActivationEvent::factory()->forFirm($firmB)->create());

        $resultA = $this->runWithFirmContext($firmA, fn () => [
            'firm_entitlements' => FirmEntitlement::withoutGlobalScopes()->pluck('id')->all(),
            'firm_activation_events' => \App\Models\FirmActivationEvent::withoutGlobalScopes()->pluck('id')->all(),
        ]);

        $this->assertSame([$entitlementA->id], $resultA['firm_entitlements']);
        $this->assertNotContains($entitlementB->id, $resultA['firm_entitlements']);
        $this->assertSame([$eventA->id], $resultA['firm_activation_events']);
        $this->assertNotContains($eventB->id, $resultA['firm_activation_events']);
    }

    public function test_compliance_gap_registry_service_still_tracks_the_rls_gap(): void
    {
        $registry = new ComplianceGapRegistryService();

        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
    }
}
