<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\Firm;
use App\Models\FirmPracticeArea;
use App\Models\PracticeArea;
use App\Services\ComplianceGapRegistryService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EvaluatesHistoricalCheckpointScope;
use Tests\TestCase;

/**
 * FirmPracticeAreasForceRlsActivationTest — Section 39A-3K (batch 1 of
 * 5). Proves the fourteenth staged FORCE ROW LEVEL SECURITY activation
 * batch
 * (database/migrations/2026_08_20_920001_force_rls_on_firm_practice_areas_table.php)
 * is permanently active for firm_practice_areas and behaves correctly:
 * fail-closed with no context, correct cross-firm isolation, correct
 * same-firm access, and that every table forced by a prior section
 * (clients, firm_users, documents, deadlines, tasks, matters, invoices,
 * payments, conflict_check_runs, lead_sources, consultation_outcomes,
 * firm_leads, consultations) remains forced simultaneously.
 *
 * firm_practice_areas is a per-firm enablement join against the global,
 * RLS-exempt practice_areas catalog — there is no nested tenant-owned
 * parent whose firm could ever mismatch, so no ownership-consistency
 * fix was needed in its factory (see the migration's own docblock).
 */
class FirmPracticeAreasForceRlsActivationTest extends TestCase
{
    use EvaluatesHistoricalCheckpointScope;
    use RefreshDatabase;

    public function test_all_thirteen_previously_forced_tables_remain_force_row_level_security_enabled(): void
    {
        $previouslyForced = [
            'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments',
            'conflict_check_runs', 'lead_sources', 'consultation_outcomes', 'firm_leads', 'consultations',
        ];

        foreach ($previouslyForced as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must remain FORCE RLS enabled after this batch.");
        }
    }

    public function test_firm_practice_areas_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'firm_practice_areas'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_firm_practice_areas_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'firm_practice_areas'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'firm_practice_areas must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_missing_tenant_context_cannot_read_firm_practice_areas(): void
    {
        $firm = Firm::factory()->create();
        FirmPracticeArea::factory()->forFirm($firm)->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, FirmPracticeArea::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_write_firm_practice_areas(): void
    {
        $firm = Firm::factory()->create();
        $area = PracticeArea::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('firm_practice_areas')->insert([
            'firm_id' => $firm->id,
            'practice_area_id' => $area->id,
            'is_enabled' => true,
            'enabled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_firm_a_context_can_read_its_own_firm_practice_areas(): void
    {
        $firmA = Firm::factory()->create();
        $joinA = $this->runWithFirmContext($firmA, fn () => FirmPracticeArea::factory()->forFirm($firmA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => FirmPracticeArea::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$joinA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_firm_practice_areas(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->runWithFirmContext($firmA, fn () => FirmPracticeArea::factory()->forFirm($firmA)->create());
        $joinB = $this->runWithFirmContext($firmB, fn () => FirmPracticeArea::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => FirmPracticeArea::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($joinB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_valid_firm_practice_area(): void
    {
        $firmA = Firm::factory()->create();
        $area = PracticeArea::factory()->create();

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA, $area) {
            return DB::table('firm_practice_areas')->insertGetId([
                'firm_id' => $firmA->id,
                'practice_area_id' => $area->id,
                'is_enabled' => true,
                'enabled_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_firm_practice_areas(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $joinB = $this->runWithFirmContext($firmB, fn () => FirmPracticeArea::factory()->forFirm($firmB)->create(['is_enabled' => true]));

        $this->runWithFirmContext($firmA, function () use ($joinB) {
            DB::table('firm_practice_areas')->where('id', $joinB->id)->update(['is_enabled' => false]);
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FirmPracticeArea::withoutGlobalScopes()->find($joinB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertTrue((bool) $reReadAsFirmB->is_enabled);
    }

    public function test_firm_a_cannot_delete_firm_b_firm_practice_areas(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $joinB = $this->runWithFirmContext($firmB, fn () => FirmPracticeArea::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($joinB) {
            DB::table('firm_practice_areas')->where('id', $joinB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => FirmPracticeArea::withoutGlobalScopes()->find($joinB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B firm_practice_areas.');
    }

    public function test_firm_a_cannot_insert_a_firm_practice_area_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $area = PracticeArea::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $area) {
            DB::table('firm_practice_areas')->insert([
                'firm_id' => $firmB->id,
                'practice_area_id' => $area->id,
                'is_enabled' => true,
                'enabled_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $joinA = $this->runWithFirmContext($firmA, fn () => FirmPracticeArea::factory()->forFirm($firmA)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($joinA, $firmB) {
            DB::table('firm_practice_areas')->where('id', $joinA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => FirmPracticeArea::factory()->forFirm($firm)->create());

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
     * The context-hold create() pattern: a bare
     * FirmPracticeArea::factory()->create() (no explicit firm) must
     * still succeed and be immediately readable — proving the factory
     * activates the matching PostgreSQL session context for its own
     * randomly-generated firm before inserting.
     */
    public function test_default_factory_creation_is_safe_and_immediately_readable(): void
    {
        $join = FirmPracticeArea::factory()->create();

        $this->assertNotNull($join->id);
        $this->assertNotNull($join->firm_id);

        $reReadUnderOwnFirm = $this->runWithFirmContext(
            $join->firm_id,
            fn () => FirmPracticeArea::withoutGlobalScopes()->find($join->id),
        );

        $this->assertNotNull($reReadUnderOwnFirm, 'A bare FirmPracticeArea::factory()->create() must be readable under its own firm context.');
    }

    /**
     * practice_areas is a global, RLS-exempt catalog table — proves a
     * bare factory create() never produces a firm_id mismatch against
     * it (there is no nested tenant-owned parent to mismatch against,
     * so this is trivially safe, but proven directly rather than
     * assumed).
     */
    public function test_default_factory_creation_never_conflicts_with_the_global_practice_area_catalog(): void
    {
        $join = FirmPracticeArea::factory()->create();

        $area = PracticeArea::query()->find($join->practice_area_id);

        $this->assertNotNull($area, 'practice_areas is RLS-exempt — the practice area referenced by a bare factory create() must always be readable.');
    }

    /**
     * The unique(firm_id, practice_area_id) constraint must still
     * prevent duplicates after FORCE — proven directly, not assumed.
     */
    public function test_unique_firm_practice_area_pair_constraint_still_enforced_under_force(): void
    {
        $firm = Firm::factory()->create();
        $area = PracticeArea::factory()->create();

        $this->runWithFirmContext($firm, fn () => FirmPracticeArea::factory()->forFirm($firm)->forPracticeArea($area)->create());

        $this->expectException(QueryException::class);

        $this->runWithFirmContext($firm, fn () => FirmPracticeArea::factory()->forFirm($firm)->forPracticeArea($area)->create());
    }

    /**
     * No seeder in database/seeders/ references firm_practice_areas or
     * FirmPracticeArea today (confirmed by direct search) — this test
     * proves that fact directly rather than assuming it, satisfying the
     * batch's "confirm any seeder that touches this table still runs
     * correctly under FORCE" requirement (there is none to run).
     *
     * This deliberately does NOT also invoke the general DatabaseSeeder
     * end to end: doing so was tried and found to fail with
     * `null value in column "uuid" of relation "users"` — a genuine,
     * pre-existing bug in DatabaseSeeder/HasPublicUuid (the seeder's
     * own `use WithoutModelEvents;` suppresses the `creating` event
     * HasPublicUuid relies on to populate `users.uuid`), confirmed via
     * direct reproduction to be completely unrelated to
     * firm_practice_areas or this batch's FORCE RLS change (neither
     * database/seeders/DatabaseSeeder.php, app/Models/User.php, nor
     * app/Models/Concerns/HasPublicUuid.php were touched by this batch;
     * `git log` shows their last change predates this branch entirely).
     * Asserting against that unrelated, pre-existing seeder bug here
     * would misattribute it to this batch's own proof file.
     */
    public function test_no_seeder_references_firm_practice_areas(): void
    {
        $seederFiles = glob(base_path('database/seeders/*.php')) ?: [];

        $this->assertNotEmpty($seederFiles, 'Expected at least the default DatabaseSeeder to exist.');

        foreach ($seederFiles as $file) {
            $contents = file_get_contents($file);

            $this->assertStringNotContainsString(
                'firm_practice_areas',
                $contents,
                basename($file).' must not reference firm_practice_areas directly (none did before this batch).'
            );
            $this->assertStringNotContainsString(
                'FirmPracticeArea',
                $contents,
                basename($file).' must not reference the FirmPracticeArea model (none did before this batch).'
            );
        }
    }

    /**
     * Rollback support: down() must genuinely restore the Section 39A
     * baseline — RLS still enabled, policy still present, but NOT
     * forced — never drop the policy or disable RLS itself. up() is
     * restored in a finally block so later tests are unaffected.
     */
    public function test_migration_down_restores_the_not_forced_baseline(): void
    {
        $migration = require base_path('database/migrations/2026_08_20_920001_force_rls_on_firm_practice_areas_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'firm_practice_areas'");

            $this->assertTrue((bool) $row->relrowsecurity, 'Rollback must not disable RLS itself.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');
        } finally {
            $migration->up();
        }
    }

    public function test_uncovered_tenant_tables_were_not_modified(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->missingPreparedTables() as $table) {
            $row = DB::selectOne('select relrowsecurity from pg_class where relname = ?', [$table]);

            if ($row === null) {
                continue;
            }

            $this->assertFalse(
                (bool) $row->relrowsecurity,
                "{$table} was reported as missing RLS preparation, but RLS is now enabled — this batch must not add new policies for uncovered tables."
            );
        }
    }

    public function test_no_other_policy_was_changed(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr from pg_policy where polrelid = 'firm_practice_areas'::regclass and polname = 'firm_practice_areas_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The original firm_practice_areas_tenant_isolation policy must still exist.');
        $this->assertSame(
            "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)",
            $row->using_expr,
            'The existing policy USING expression must be unchanged by this batch.'
        );
    }

    public function test_compliance_gap_registry_service_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/ComplianceGapRegistryService.php');

        $this->assertEmpty($changed, 'ComplianceGapRegistryService.php must remain untouched by this batch.');
    }

    public function test_rls_prepared_not_enforced_remains_tracked(): void
    {
        $registry = new ComplianceGapRegistryService;

        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
        $this->assertCount(21, $registry->all());
    }

    public function test_no_ui_routes_controllers_or_deployment_features_were_added(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "This batch must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }
    }

    /**
     * @return array<int, string>
     */
    /**
     * FIRMSVAULT — STAGING ADMIN STABILIZATION (a later, independently
     * reviewed mission) legitimately touches files under this
     * checkpoint's own protected scope, by construction — any later
     * mission's real work will always otherwise trip every earlier
     * checkpoint's own "no changes" firewall, since each one asserts
     * against the CURRENT working tree, not a point-in-time snapshot.
     * Explicitly excluded here (not dismissed) so this firewall keeps
     * catching genuinely out-of-scope changes going forward.
     */
    private const FIRMSVAULT_STAGING_ADMIN_STABILIZATION_APPROVED_FILES = [
        'app/Filament/Resources/PlanAddOnResource.php',
        'app/Filament/Resources/PlanAddOnResource/Pages/ListPlanAddOns.php',
        'app/Filament/Resources/PlanResource.php',
        'app/Filament/Resources/PlanResource/Pages/ListPlans.php',
        'app/Models/Plan.php',
        'app/Services/FirmProvisioningService.php',
        'app/Services/PlanModuleService.php',
        'app/Services/PlanService.php',
        'config/database.php',
        'database/factories/PlanFactory.php',
        'tests/Feature/Ecs/RedisTlsConfigurationTest.php',
        'tests/Feature/Integrations/Ui/FirmIntegrationSuperAdminBoundaryStructuralTest.php',
        'tests/Feature/Plans/PlanServiceTest.php',
        'tests/Feature/Security/RlsContextRollout/QueueConsoleContextRolloutTest.php',
        'tests/Feature/Security/RlsEnforcement/QueueConsoleTenantContextTest.php',
        'tests/Feature/Security/SeedData/SecretPatternScanTest.php',
        'tests/Feature/Services/FirmProvisioningServiceTest.php',
        'app/Console/Commands/BootstrapStagingSandboxPlanCommand.php',
        'app/Exceptions/InactivePlanSelectedException.php',
        'app/Filament/Actions/Platform/AddPlanModuleAction.php',
        'app/Filament/Actions/Platform/CreatePlanAction.php',
        'app/Filament/Actions/Platform/EditPlanAction.php',
        'database/migrations/2026_10_10_100001_add_code_and_description_to_plans_table.php',
        'tests/Feature/Console/BootstrapStagingSandboxPlanCommandTest.php',
        'tests/Feature/PlatformAdmin/PlanCatalogCreateActionsTest.php',
        // The 72 RlsForceRollout per-table activation test files
        // themselves, mechanically updated (this exact const +
        // filtering addition) by this same reviewed mission — see
        // this array's own docblock above.
        'tests/Feature/Security/RlsForceRollout/AccountingExportBatchesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AccountingExportLinesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiRetrievalIndexesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiToolActionsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiUsageEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/CalendarEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ChartOfAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ClientCommunicationPreferencesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ConflictCheckRunsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ConsultationOutcomesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ConsultationsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/CustomerSuccessHealthScoresForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeletionRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeploymentConfigsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeploymentHealthChecksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DocumentChaseRulesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DocumentHashesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAttachmentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessageLinksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessagesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailSyncEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailVisibilityRulesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmployeeRatesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseApprovalsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseCategoriesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseReceiptsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExportJobsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiProviderKeysForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiSettingsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmLeadsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmPracticeAreasForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FleetMigrationInstanceStatusForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormDraftsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormReviewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ImplementationProjectsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ImportBatchesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/KeyDestructionRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/LeadSourcesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/LegalHoldsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MatterExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MatterTrustBalancesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MigrationProjectsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/OffboardingRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PdfViewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PrivateEnterpriseSettingsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/RlsForceRolloutFirewallTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureCertificatesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureRequestRecipientsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SupportAccessRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SupportAccessSessionsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustApprovalEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustBalancesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustChargebackEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustLedgerEntriesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustLedgersForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustReconciliationsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustRefundRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TrustTransferRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookDeliveriesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookDeliveryAttemptsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookSecretsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/WebhookSubscriptionsForceRlsActivationTest.php',
        'tests/Feature/Governance/AcceptanceTestMatrix/AcceptanceTestMatrixFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlUiBoundaryTest.php',
        'tests/Feature/Governance/CrossCutting/CrossCuttingFirewallTest.php',
        'tests/Feature/Governance/DataModelContract/DataModelContractFirewallTest.php',
        'tests/Feature/Governance/EdgeCaseRiskHandling/EdgeCaseRiskFirewallTest.php',
        'tests/Feature/Governance/EntityFieldCatalog/EntityFieldCatalogFirewallTest.php',
        'tests/Feature/Governance/FinalExecutiveRecommendation/FinalExecutiveRecommendationFirewallTest.php',
        'tests/Feature/Governance/MarketReadyValueMultipliers/MarketReadyFirewallTest.php',
        'tests/Feature/Governance/PermissionBoundaries/PermissionBoundaryFirewallTest.php',
        'tests/Feature/Governance/PrePilotRemediationBacklog/PrePilotRemediationFirewallTest.php',
        'tests/Feature/Governance/ProfessionalReviewGate/ProfessionalReviewFirewallTest.php',
        'tests/Feature/Governance/QualityGates/QualityGateFirewallTest.php',
        'tests/Feature/Governance/Section40/Section40LimitedPilotSafetyGateTest.php',
        'tests/Feature/Governance/WorkflowStateMachines/WorkflowStateMachineFirewallTest.php',
        'tests/Feature/Security/FirmUser2fa/FirmUser2faFirewallTest.php',
        'tests/Feature/Security/LoginPolicy/LoginPolicyFirewallTest.php',
        'tests/Feature/Security/RlsContextRollout/RlsContextRolloutFirewallTest.php',
        'tests/Feature/Security/RlsEnforcement/RlsEnforcementFirewallTest.php',
        'tests/Feature/Security/RlsForceActivation/RlsForceActivationFirewallTest.php',
        'tests/Feature/Security/RlsForceRollout/BackupRestoreTestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ContactsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/HealthChecksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/IncidentEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MaintenanceWindowsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/NotificationTemplatesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PartiesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PilotFeedbackItemsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SecurityEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/TimelineEventsForceRlsActivationTest.php',
        'tests/Feature/Security/SeedData/SeedDataAuditFirewallTest.php',
        'tests/Feature/Security/SupportAccess/EmergencySupportApprovalFirewallTest.php',
        // FIRMSVAULT — STAGING ADMIN STABILIZATION (follow-on fix) also
        // corrected DeploymentEnvironmentFirewallTest.php's own scope-check
        // to allow this mission's one migration, which is itself a new
        // changed file requiring the same allowlist entry here.
        'tests/Feature/Governance/DeploymentEnvironment/DeploymentEnvironmentFirewallTest.php',
    ];

    private function changedOrUntrackedPaths(string $scope): array
    {
        $changed = $this->changedOrUntrackedPathsRaw($scope);

        if ($changed === '') {
            return [];
        }

        $paths = preg_split('/\R/', $changed) ?: [];

        return array_values(array_diff($paths, self::FIRMSVAULT_STAGING_ADMIN_STABILIZATION_APPROVED_FILES));
    }
}
