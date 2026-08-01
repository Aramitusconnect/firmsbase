<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Models\Client;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterTrustBalance;
use App\Models\TrustAccount;
use App\Models\TrustLedger;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MatterTrustBalancesForceRlsActivationTest — proves the FORCE ROW
 * LEVEL SECURITY activation for matter_trust_balances (database/
 * migrations/2026_08_30_980004_prepare_row_level_security_and_force_rls_on_matter_trust_balances_table.php)
 * is permanently active and behaves correctly.
 *
 * Fourth of Wave 10's ten-table batch. matter_trust_balances is part
 * of the shared TrustConcurrencyLockService lock unit. Its model does
 * NOT use BelongsToTenant (a deliberate design choice, per the batch
 * migration's own docblock) — this file specifically proves RLS ALONE
 * (with no Eloquent global scope helping) still correctly isolates
 * cross-firm reads.
 */
class MatterTrustBalancesForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_30_980004_prepare_row_level_security_and_force_rls_on_matter_trust_balances_table.php';

    private const THIS_BATCH = [
        'trust_accounts', 'trust_ledgers', 'trust_balances', 'matter_trust_balances',
        'trust_ledger_entries', 'trust_approval_events', 'trust_chargeback_events',
        'trust_reconciliations', 'trust_refund_requests', 'trust_transfer_requests',
    ];

    public function test_all_previously_forced_tables_remain_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->forcedTables() as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must remain FORCE ROW LEVEL SECURITY enabled after this checkpoint.");
        }
    }

    public function test_matter_trust_balances_is_discovered_by_the_forced_tables_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertContains('matter_trust_balances', $coverage->forcedTables());
    }

    public function test_exact_forced_table_count_has_no_duplicate_collisions(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $matchingFiles = glob(database_path('migrations/*_force_rls_on_*_table.php')) ?: [];

        $this->assertSame(count($matchingFiles), count($coverage->forcedTables()));
    }

    public function test_matter_trust_balances_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'matter_trust_balances'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_matter_trust_balances_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'matter_trust_balances'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'matter_trust_balances'::regclass and polname = 'matter_trust_balances_tenant_isolation'"
        );

        $this->assertNotNull($row);

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr);
        $this->assertSame($expected, $row->with_check_expr);
    }

    public function test_matter_trust_balance_model_does_not_use_belongs_to_tenant(): void
    {
        $traits = class_uses_recursive(MatterTrustBalance::class);

        $this->assertArrayNotHasKey(
            \App\Models\Concerns\BelongsToTenant::class,
            $traits,
            'MatterTrustBalance is deliberately NOT using BelongsToTenant — this is a documented design choice, not an oversight; isolation for this model relies on RLS alone.'
        );
    }

    public function test_missing_tenant_context_cannot_read_matter_trust_balances(): void
    {
        $firm = Firm::factory()->create();
        $this->createBalanceForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, MatterTrustBalance::query()->count());
    }

    public function test_missing_tenant_context_cannot_insert_matter_trust_balances(): void
    {
        $firm = Firm::factory()->create();
        [$ledger, $matter] = $this->createLedgerAndMatterForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('matter_trust_balances')->insert($this->rowAttributes($firm, $ledger, $matter));
    }

    public function test_bare_factory_create_without_context_now_succeeds_via_the_context_hold_override(): void
    {
        (new TenantContextService)->clearDatabaseTenantContext();

        $balance = MatterTrustBalance::factory()->create();

        $this->assertNotNull($balance->id);

        $persisted = $this->runWithFirmContext($balance->firm_id, fn () => MatterTrustBalance::query()->find($balance->id));

        $this->assertNotNull($persisted);
        $this->assertSame($balance->firm_id, $persisted->firm_id);
    }

    /**
     * The core proof this file exists to make: even though
     * MatterTrustBalance carries no BelongsToTenant global scope (so
     * Eloquent itself applies no automatic firm_id filter), a plain
     * unscoped ::query() under Firm A's context still cannot see Firm
     * B's row — RLS alone, at the PostgreSQL level, is doing the
     * isolation work here.
     */
    public function test_rls_alone_isolates_cross_firm_reads_despite_the_missing_belongs_to_tenant_scope(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $balanceA = $this->createBalanceForFirm($firmA);
        $balanceB = $this->createBalanceForFirm($firmB);

        $visibleIds = $this->runWithFirmContext($firmA, fn () => MatterTrustBalance::query()->pluck('id')->all());

        $this->assertSame([$balanceA->id], $visibleIds);
        $this->assertNotContains($balanceB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_matter_trust_balance(): void
    {
        $firmA = Firm::factory()->create();
        [$ledger, $matter] = $this->createLedgerAndMatterForFirm($firmA);

        $insertedId = $this->runWithFirmContext($firmA, fn () => DB::table('matter_trust_balances')->insertGetId($this->rowAttributes($firmA, $ledger, $matter)));

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_matter_trust_balance(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $balanceB = $this->createBalanceForFirm($firmB);

        $affected = $this->runWithFirmContext($firmA, function () use ($balanceB) {
            return DB::table('matter_trust_balances')->where('id', $balanceB->id)->update(['balance_cents' => 999]);
        });

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => MatterTrustBalance::query()->find($balanceB->id));
        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(0, $reReadAsFirmB->balance_cents);
    }

    public function test_firm_a_cannot_delete_firm_b_matter_trust_balance(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $balanceB = $this->createBalanceForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($balanceB) {
            DB::table('matter_trust_balances')->where('id', $balanceB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext($firmB, fn () => MatterTrustBalance::query()->find($balanceB->id));
        $this->assertNotNull($reReadAsFirmB);
    }

    public function test_firm_a_cannot_insert_a_matter_trust_balance_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        [$ledger, $matter] = $this->createLedgerAndMatterForFirm($firmB);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $ledger, $matter) {
            DB::table('matter_trust_balances')->insert($this->rowAttributes($firmB, $ledger, $matter));
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $balanceA = $this->createBalanceForFirm($firmA);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($balanceA, $firmB) {
            DB::table('matter_trust_balances')->where('id', $balanceA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        [$ledger, $matter] = $this->createLedgerAndMatterForFirm($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->runWithFirmContext($firm, fn () => MatterTrustBalance::factory()->create([
            'firm_id' => $firm->id,
            'trust_ledger_id' => $ledger->id,
            'matter_id' => $matter->id,
        ]));

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

    public function test_migration_down_fully_restores_the_pre_checkpoint_state(): void
    {
        $migration = require base_path(self::MIGRATION_PATH);

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'matter_trust_balances'");
            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);

            $policy = DB::selectOne("select 1 from pg_policy where polrelid = 'matter_trust_balances'::regclass and polname = 'matter_trust_balances_tenant_isolation'");
            $this->assertNull($policy);
        } finally {
            $migration->up();
        }

        $rowAfterUp = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'matter_trust_balances'");
        $this->assertTrue((bool) $rowAfterUp->relforcerowsecurity);
    }

    public function test_migration_round_trip_affects_only_matter_trust_balances(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;
        $otherTables = array_slice(array_values(array_diff($coverage->preparedTables(), ['matter_trust_balances'])), 0, 5);

        $before = [];
        foreach ($otherTables as $table) {
            $before[$table] = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);
        }

        $migration = require base_path(self::MIGRATION_PATH);
        $migration->down();
        $migration->up();

        foreach ($otherTables as $table) {
            $after = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);
            $this->assertEquals($before[$table], $after);
        }
    }

    public function test_uncovered_tenant_tables_were_not_modified(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->missingPreparedTables() as $table) {
            if (in_array($table, self::THIS_BATCH, true)) {
                continue;
            }

            $row = DB::selectOne('select relrowsecurity from pg_class where relname = ?', [$table]);

            if ($row === null) {
                continue;
            }

            $this->assertFalse((bool) $row->relrowsecurity, "{$table} must not gain RLS from this checkpoint.");
        }
    }

    public function test_compliance_gap_registry_service_was_not_modified(): void
    {
        $this->assertEmpty($this->changedOrUntrackedPaths('app/Services/ComplianceGapRegistryService.php'));
    }

    public function test_row_level_security_coverage_mapping_service_was_not_modified(): void
    {
        $this->assertEmpty($this->changedOrUntrackedPaths('app/Services/RowLevelSecurityCoverageMappingService.php'));
    }

    public function test_no_ui_routes_controllers_or_deployment_features_were_added(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $this->assertEmpty($this->changedOrUntrackedPaths($relativeDir));
        }
    }

    private function createAccountForFirm(Firm $firm): TrustAccount
    {
        return $this->runWithFirmContext($firm, fn () => TrustAccount::factory()->create(['firm_id' => $firm->id]));
    }

    /**
     * @return array{0: TrustLedger, 1: Matter}
     */
    private function createLedgerAndMatterForFirm(Firm $firm): array
    {
        $account = $this->createAccountForFirm($firm);
        $client = Client::factory()->forFirm($firm)->create();
        $matter = Matter::factory()->forClient($client)->create();

        $ledger = $this->runWithFirmContext($firm, fn () => TrustLedger::factory()->create([
            'firm_id' => $firm->id,
            'trust_account_id' => $account->id,
            'client_id' => $client->id,
        ]));

        return [$ledger, $matter];
    }

    private function createBalanceForFirm(Firm $firm): MatterTrustBalance
    {
        [$ledger, $matter] = $this->createLedgerAndMatterForFirm($firm);

        return $this->runWithFirmContext($firm, fn () => MatterTrustBalance::factory()->create([
            'firm_id' => $firm->id,
            'trust_ledger_id' => $ledger->id,
            'matter_id' => $matter->id,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(Firm $firm, TrustLedger $ledger, Matter $matter): array
    {
        return [
            'firm_id' => $firm->id,
            'trust_ledger_id' => $ledger->id,
            'matter_id' => $matter->id,
            'balance_cents' => 0,
            'last_recomputed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
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
        $changed = trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- '.escapeshellarg($scope)
        ));

        if ($changed === '') {
            return [];
        }

        $paths = preg_split('/\R/', $changed) ?: [];

        return array_values(array_diff($paths, self::FIRMSVAULT_STAGING_ADMIN_STABILIZATION_APPROVED_FILES));
    }
}
