<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\ChartOfAccountType;
use App\Enums\EntitlementSource;
use App\Models\ChartOfAccount;
use App\Models\Firm;
use App\Services\AccountingEntitlementPolicyService;
use App\Services\ChartOfAccountsService;
use App\Services\EntitlementService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ChartOfAccountsForceRlsActivationTest — proves the FORCE ROW LEVEL
 * SECURITY activation for chart_of_accounts (database/migrations/
 * 2026_08_27_950018_prepare_row_level_security_and_force_rls_on_
 * chart_of_accounts_table.php) is permanently active and behaves
 * correctly: fail-closed with no context, correct same-firm access,
 * correct cross-firm isolation on read/update/delete, insert and
 * ownership-reassignment protection under the explicit WITH CHECK
 * clause, that every previously-prepared/forced table remains forced
 * simultaneously, and that ChartOfAccountsService (the sole writer of
 * this table) still functions correctly under FORCE.
 *
 * This is the FIRST checkpoint of this batch's 7-table combined Wave 4
 * accounting/expense-domain activation (chart_of_accounts,
 * expense_categories, expenses, expense_receipts, expense_approvals,
 * accounting_export_batches, accounting_export_lines — see this
 * batch's approved Phase 3 design document for why all 7 land
 * together as one atomic batch rather than 7 independent checkpoints).
 * Like every missingPreparedTables() checkpoint before it,
 * chart_of_accounts still appears in RowLevelSecurityCoverageMappingService::
 * missingPreparedTables() at the point this test runs — the registry is
 * updated once by the coordinator in a later, separate wave-integration
 * commit, not by this batch. Consequently this test does NOT assert
 * chart_of_accounts appears in $coverage->preparedTables(), and does
 * NOT assert any exact "N prepared tables" count.
 *
 * chart_of_accounts has no in-scope parent FK of its own (it is the
 * root of the ownership hop for expense_categories/accounting_export_lines,
 * not the child end of one) — so, unlike its sibling checkpoints in
 * this batch, there is no "related-model cross-firm-mismatch" gap test
 * for this table specifically; that gap is proven instead in
 * ExpenseCategoriesForceRlsActivationTest and
 * AccountingExportLinesForceRlsActivationTest, which each hold a real
 * FK into this table.
 */
class ChartOfAccountsForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_27_950018_prepare_row_level_security_and_force_rls_on_chart_of_accounts_table.php';

    private ChartOfAccountsService $service;

    private EntitlementService $entitlements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlements = app(EntitlementService::class);
        $this->service = new ChartOfAccountsService(new AccountingEntitlementPolicyService($this->entitlements));
    }

    private function enableExpenses(Firm $firm): void
    {
        $this->entitlements->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);
    }

    // ---------------------------------------------------------------
    // FORCE state / policy proofs
    // ---------------------------------------------------------------

    public function test_all_previously_prepared_tables_remain_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->preparedTables() as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must remain FORCE ROW LEVEL SECURITY enabled after this checkpoint.");
        }
    }

    public function test_all_previously_forced_tables_remain_force_row_level_security_enabled(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        foreach ($coverage->forcedTables() as $table) {
            $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertTrue((bool) $row->relforcerowsecurity, "{$table} must remain FORCE ROW LEVEL SECURITY enabled after this checkpoint.");
        }
    }

    public function test_chart_of_accounts_is_discovered_by_the_forced_tables_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertContains('chart_of_accounts', $coverage->forcedTables());
    }

    public function test_chart_of_accounts_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'chart_of_accounts'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_chart_of_accounts_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'chart_of_accounts'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity, 'chart_of_accounts must have permanent FORCE ROW LEVEL SECURITY active.');
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'chart_of_accounts'::regclass and polname = 'chart_of_accounts_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The chart_of_accounts_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr);
        $this->assertSame($expected, $row->with_check_expr);
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_chart_of_accounts(): void
    {
        $firm = Firm::factory()->create();
        ChartOfAccount::factory()->forFirm($firm)->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, ChartOfAccount::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_insert_chart_of_accounts(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('chart_of_accounts')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'firm_id' => $firm->id,
            'account_code' => '6000',
            'account_name' => 'Office Supplies',
            'account_type' => ChartOfAccountType::Expense->value,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_chart_of_account(): void
    {
        $firmA = Firm::factory()->create();
        $accountA = $this->runWithFirmContext($firmA, fn () => ChartOfAccount::factory()->forFirm($firmA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => ChartOfAccount::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$accountA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_chart_of_account(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->runWithFirmContext($firmA, fn () => ChartOfAccount::factory()->forFirm($firmA)->create());
        $accountB = $this->runWithFirmContext($firmB, fn () => ChartOfAccount::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => ChartOfAccount::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($accountB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_chart_of_account(): void
    {
        $firmA = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA) {
            return DB::table('chart_of_accounts')->insertGetId([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'firm_id' => $firmA->id,
                'account_code' => '6000',
                'account_name' => 'Office Supplies',
                'account_type' => ChartOfAccountType::Expense->value,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_chart_of_account(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $accountB = $this->runWithFirmContext($firmB, fn () => ChartOfAccount::factory()->forFirm($firmB)->create(['is_active' => true]));

        $affected = $this->runWithFirmContext($firmA, function () use ($accountB) {
            return DB::table('chart_of_accounts')->where('id', $accountB->id)->update(['is_active' => false]);
        });

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => ChartOfAccount::withoutGlobalScopes()->find($accountB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertTrue($reReadAsFirmB->is_active);
    }

    public function test_firm_a_cannot_delete_firm_b_chart_of_account(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $accountB = $this->runWithFirmContext($firmB, fn () => ChartOfAccount::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($accountB) {
            DB::table('chart_of_accounts')->where('id', $accountB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => ChartOfAccount::withoutGlobalScopes()->find($accountB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B chart_of_accounts.');
    }

    public function test_firm_a_cannot_insert_a_chart_of_account_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('chart_of_accounts')->insert([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'firm_id' => $firmB->id,
                'account_code' => '6000',
                'account_name' => 'Office Supplies',
                'account_type' => ChartOfAccountType::Expense->value,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $accountA = $this->runWithFirmContext($firmA, fn () => ChartOfAccount::factory()->forFirm($firmA)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($accountA, $firmB) {
            DB::table('chart_of_accounts')->where('id', $accountA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => ChartOfAccount::factory()->forFirm($firm)->create());

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
    // ChartOfAccountsService writer regression proofs
    // ---------------------------------------------------------------

    public function test_create_succeeds_under_force_and_clears_context(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $account = $this->service->create($firm, '6000', 'Office Supplies', ChartOfAccountType::Expense);

        $this->assertNotNull($account->id);
        $this->assertNoDatabaseTenantContext('create() must clear its own internal context wrap after success.');

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => ChartOfAccount::withoutGlobalScopes()->find($account->id),
        );

        $this->assertNotNull($persisted, 'create() must genuinely persist a chart_of_accounts row, readable under its own firm context.');
        $this->assertSame('6000', $persisted->account_code);
    }

    public function test_deactivate_succeeds_under_force_and_clears_context(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $account = $this->service->create($firm, '6100', 'Travel', ChartOfAccountType::Expense);

        (new TenantContextService)->clearDatabaseTenantContext();

        $deactivated = $this->service->deactivate($firm, $account);

        $this->assertFalse($deactivated->is_active);
        $this->assertNoDatabaseTenantContext('deactivate() must clear its own internal context wrap after success.');
    }

    public function test_create_blocked_when_module_disabled(): void
    {
        $firm = Firm::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->service->create($firm, '6000', 'Office Supplies', ChartOfAccountType::Expense);
    }

    // ---------------------------------------------------------------
    // Factory correctness proofs
    // ---------------------------------------------------------------

    public function test_chart_of_account_factory_default_creation_is_safe(): void
    {
        $account = ChartOfAccount::factory()->create();

        $this->assertNotNull($account->id);
        $this->assertNotNull($account->firm_id);

        $persisted = $this->runWithFirmContext(
            $account->firm_id,
            fn () => ChartOfAccount::withoutGlobalScopes()->find($account->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
    }

    // ---------------------------------------------------------------
    // Migration down()/up() restoration proofs
    // ---------------------------------------------------------------

    public function test_migration_down_fully_restores_the_pre_checkpoint_state(): void
    {
        $migration = require base_path(self::MIGRATION_PATH);

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'chart_of_accounts'");
            $this->assertFalse((bool) $row->relrowsecurity, 'Rollback must fully disable RLS, not merely clear FORCE.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            $policy = DB::selectOne(
                "select 1 from pg_policy where polrelid = 'chart_of_accounts'::regclass and polname = 'chart_of_accounts_tenant_isolation'"
            );
            $this->assertNull($policy, 'Rollback must drop the policy this checkpoint created.');
        } finally {
            $migration->up();
        }
    }

    public function test_migration_round_trip_affects_only_chart_of_accounts(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $sampledPrepared = array_slice($coverage->preparedTables(), 0, 5);
        $otherTables = array_merge($sampledPrepared, [
            'expenses', // still unprepared at this point in the batch's own migration order, must remain untouched
            'expense_categories', // still unprepared, must remain untouched
        ]);

        $before = [];
        foreach ($otherTables as $table) {
            $before[$table] = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);
        }

        $migration = require base_path(self::MIGRATION_PATH);
        $migration->down();
        $migration->up();

        foreach ($otherTables as $table) {
            $after = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertSame((bool) $before[$table]->relrowsecurity, (bool) $after->relrowsecurity, "{$table}'s relrowsecurity must be unaffected.");
            $this->assertSame((bool) $before[$table]->relforcerowsecurity, (bool) $after->relforcerowsecurity, "{$table}'s relforcerowsecurity must be unaffected.");
        }
    }

    // ---------------------------------------------------------------
    // Scope proofs
    // ---------------------------------------------------------------

    public function test_no_ui_routes_controllers_or_deployment_features_were_added(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "This batch must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }

        $this->assertDirectoryDoesNotExist(base_path('app/Filament'));
        $this->assertDirectoryDoesNotExist(base_path('app/Livewire'));
    }

    public function test_compliance_gap_registry_service_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/ComplianceGapRegistryService.php');

        $this->assertEmpty($changed, 'ComplianceGapRegistryService.php must remain untouched by this batch.');
    }

    public function test_rls_coverage_mapping_service_and_gap_registry_docs_were_not_modified(): void
    {
        foreach ([
            'app/Services/RowLevelSecurityCoverageMappingService.php',
            'docs/governance/rls-gap-registry.md',
        ] as $reservedPath) {
            $changed = $this->changedOrUntrackedPaths($reservedPath);

            $this->assertEmpty($changed, "{$reservedPath} is reserved for a later, separate wave-integration commit.");
        }
    }

    /**
     * This batch's expected file set (§6 of the approved design):
     * 7 migrations, 9 services, 1 model, 7 factories, 7 new
     * *ForceRlsActivationTest files, and the regression-fixed existing
     * test files this batch's FORCE activation required. All 7 tables
     * land together as ONE combined batch, so every one of the 7 new
     * *ForceRlsActivationTest files declares the SAME full expected set
     * rather than only its own table's narrower slice.
     */
    public function test_only_this_batchs_expected_files_were_changed(): void
    {
        $changed = $this->changedOrUntrackedPaths('.');

        $unexpected = array_values(array_diff($changed, $this->allowedFiles()));

        $this->assertEmpty($unexpected, 'Unexpected files changed for this batch: '.implode(', ', $unexpected));
    }

    /**
     * @return array<int, string>
     */
    private function allowedFiles(): array
    {
        return [
            'database/migrations/2026_08_27_950018_prepare_row_level_security_and_force_rls_on_chart_of_accounts_table.php',
            'database/migrations/2026_08_27_950019_prepare_row_level_security_and_force_rls_on_expense_categories_table.php',
            'database/migrations/2026_08_27_950020_prepare_row_level_security_and_force_rls_on_expenses_table.php',
            'database/migrations/2026_08_27_950021_prepare_row_level_security_and_force_rls_on_expense_receipts_table.php',
            'database/migrations/2026_08_27_950022_prepare_row_level_security_and_force_rls_on_expense_approvals_table.php',
            'database/migrations/2026_08_27_950023_prepare_row_level_security_and_force_rls_on_accounting_export_batches_table.php',
            'database/migrations/2026_08_27_950024_prepare_row_level_security_and_force_rls_on_accounting_export_lines_table.php',
            'app/Services/ExpenseService.php',
            'app/Services/ExpenseCategoryService.php',
            'app/Services/ChartOfAccountsService.php',
            'app/Services/ExpenseApprovalService.php',
            'app/Services/ExpenseReceiptService.php',
            'app/Services/AccountingExportBatchService.php',
            'app/Services/AccountingExportLineBuilderService.php',
            'app/Services/AccountingExportSimulationService.php',
            'app/Services/ExpenseReportingService.php',
            'app/Models/ExpenseApproval.php',
            'database/factories/ExpenseFactory.php',
            'database/factories/ExpenseCategoryFactory.php',
            'database/factories/ChartOfAccountFactory.php',
            'database/factories/ExpenseApprovalFactory.php',
            'database/factories/ExpenseReceiptFactory.php',
            'database/factories/AccountingExportBatchFactory.php',
            'database/factories/AccountingExportLineFactory.php',
            'tests/Feature/Security/RlsForceRollout/ChartOfAccountsForceRlsActivationTest.php',
            'tests/Feature/Security/RlsForceRollout/ExpenseCategoriesForceRlsActivationTest.php',
            'tests/Feature/Security/RlsForceRollout/ExpensesForceRlsActivationTest.php',
            'tests/Feature/Security/RlsForceRollout/ExpenseReceiptsForceRlsActivationTest.php',
            'tests/Feature/Security/RlsForceRollout/ExpenseApprovalsForceRlsActivationTest.php',
            'tests/Feature/Security/RlsForceRollout/AccountingExportBatchesForceRlsActivationTest.php',
            'tests/Feature/Security/RlsForceRollout/AccountingExportLinesForceRlsActivationTest.php',
            'tests/Feature/Accounting/ChartOfAccounts/ChartOfAccountsServiceTest.php',
            'tests/Feature/Accounting/Expenses/ExpenseServiceTest.php',
            'tests/Feature/Accounting/Expenses/ExpenseApprovalServiceTest.php',
            'tests/Feature/Accounting/Expenses/ExpenseReceiptServiceTest.php',
            'tests/Feature/Accounting/Expenses/MatterExpenseServiceTest.php',
            'tests/Feature/Accounting/Export/AccountingExportSimulationServiceTest.php',
            'tests/Feature/Accounting/Reporting/ExpenseReportingServiceTest.php',
            // Added during independent Phase 7 test review: this
            // pre-existing tenant-isolation test relied on
            // TenantContextResolver::activateForFirm() alone (PHP-memory
            // context only), which no longer suffices now that expenses
            // and expense_categories both have permanent FORCE ROW LEVEL
            // SECURITY — the real PostgreSQL app.current_firm_id session
            // setting must also be established. Narrowly updated to use
            // runWithFirmContext() instead, matching every other test in
            // this batch; see the file's own updated docblocks.
            'tests/Feature/TenantIsolation/AccountingTenantIsolationTest.php',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function changedOrUntrackedPaths(string $scope): array
    {
        $changed = trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- '.escapeshellarg($scope)
        ));

        if ($changed === '') {
            return [];
        }

        return preg_split('/\R/', $changed) ?: [];
    }
}
