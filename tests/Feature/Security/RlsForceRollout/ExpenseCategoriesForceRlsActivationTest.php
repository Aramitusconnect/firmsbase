<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\ChartOfAccountType;
use App\Enums\EntitlementSource;
use App\Models\ChartOfAccount;
use App\Models\ExpenseCategory;
use App\Models\Firm;
use App\Services\AccountingEntitlementPolicyService;
use App\Services\ChartOfAccountsService;
use App\Services\EntitlementService;
use App\Services\ExpenseCategoryService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use App\Services\TenantSafeAccountingPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ExpenseCategoriesForceRlsActivationTest — proves the FORCE ROW LEVEL
 * SECURITY activation for expense_categories (database/migrations/
 * 2026_08_27_950019_prepare_row_level_security_and_force_rls_on_
 * expense_categories_table.php) is permanently active and behaves
 * correctly. This is the SECOND checkpoint of this batch's 7-table
 * combined Wave 4 accounting/expense-domain activation — see
 * ChartOfAccountsForceRlsActivationTest's own docblock for the full
 * combined-batch rationale.
 *
 * Known, stated (not hidden) residual gap: this migration does NOT
 * close the transitive cross-firm foreign-key gap between
 * expense_categories.firm_id and the real firm_id of the
 * chart_of_accounts row chart_of_accounts_id points at — see the
 * migration's own docblock, part (b). A test below proves — rather
 * than merely asserts — that a raw insert can still create this
 * mismatch.
 */
class ExpenseCategoriesForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_27_950019_prepare_row_level_security_and_force_rls_on_expense_categories_table.php';

    private ExpenseCategoryService $service;

    private ChartOfAccountsService $coaService;

    private EntitlementService $entitlements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlements = app(EntitlementService::class);
        $this->service = new ExpenseCategoryService(
            new AccountingEntitlementPolicyService($this->entitlements),
            new TenantSafeAccountingPolicyService(),
        );
        $this->coaService = new ChartOfAccountsService(new AccountingEntitlementPolicyService($this->entitlements));
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

    public function test_expense_categories_is_discovered_by_the_forced_tables_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertContains('expense_categories', $coverage->forcedTables());
    }

    public function test_expense_categories_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'expense_categories'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_expense_categories_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'expense_categories'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'expense_categories'::regclass and polname = 'expense_categories_tenant_isolation'"
        );

        $this->assertNotNull($row);

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr);
        $this->assertSame($expected, $row->with_check_expr);
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_expense_categories(): void
    {
        $firm = Firm::factory()->create();
        ExpenseCategory::factory()->forFirm($firm)->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, ExpenseCategory::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_insert_expense_categories(): void
    {
        $firm = Firm::factory()->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('expense_categories')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'firm_id' => $firm->id,
            'chart_of_accounts_id' => null,
            'name' => 'Travel',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_expense_category(): void
    {
        $firmA = Firm::factory()->create();
        $categoryA = $this->runWithFirmContext($firmA, fn () => ExpenseCategory::factory()->forFirm($firmA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => ExpenseCategory::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$categoryA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_expense_category(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->runWithFirmContext($firmA, fn () => ExpenseCategory::factory()->forFirm($firmA)->create());
        $categoryB = $this->runWithFirmContext($firmB, fn () => ExpenseCategory::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => ExpenseCategory::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($categoryB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_expense_category(): void
    {
        $firmA = Firm::factory()->create();

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA) {
            return DB::table('expense_categories')->insertGetId([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'firm_id' => $firmA->id,
                'chart_of_accounts_id' => null,
                'name' => 'Travel',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_expense_category(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $categoryB = $this->runWithFirmContext($firmB, fn () => ExpenseCategory::factory()->forFirm($firmB)->create(['is_active' => true]));

        $affected = $this->runWithFirmContext($firmA, function () use ($categoryB) {
            return DB::table('expense_categories')->where('id', $categoryB->id)->update(['is_active' => false]);
        });

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => ExpenseCategory::withoutGlobalScopes()->find($categoryB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertTrue($reReadAsFirmB->is_active);
    }

    public function test_firm_a_cannot_delete_firm_b_expense_category(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $categoryB = $this->runWithFirmContext($firmB, fn () => ExpenseCategory::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($categoryB) {
            DB::table('expense_categories')->where('id', $categoryB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => ExpenseCategory::withoutGlobalScopes()->find($categoryB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B expense_categories.');
    }

    public function test_firm_a_cannot_insert_an_expense_category_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB) {
            DB::table('expense_categories')->insert([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'firm_id' => $firmB->id,
                'chart_of_accounts_id' => null,
                'name' => 'Travel',
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
        $categoryA = $this->runWithFirmContext($firmA, fn () => ExpenseCategory::factory()->forFirm($firmA)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($categoryA, $firmB) {
            DB::table('expense_categories')->where('id', $categoryA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => ExpenseCategory::factory()->forFirm($firm)->create());

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
    // ExpenseCategoryService writer regression proofs
    // ---------------------------------------------------------------

    public function test_create_succeeds_under_force_and_clears_context(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);

        (new TenantContextService)->clearDatabaseTenantContext();

        $category = $this->service->create($firm, 'Travel');

        $this->assertNotNull($category->id);
        $this->assertNoDatabaseTenantContext('create() must clear its own internal context wrap after success.');

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => ExpenseCategory::withoutGlobalScopes()->find($category->id),
        );

        $this->assertNotNull($persisted);
    }

    public function test_map_to_chart_of_account_succeeds_under_force(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $category = $this->service->create($firm, 'Travel');
        $account = $this->coaService->create($firm, '6100', 'Travel Expense', ChartOfAccountType::Expense);

        (new TenantContextService)->clearDatabaseTenantContext();

        $mapped = $this->service->mapToChartOfAccount($firm, $category, $account);

        $this->assertSame($account->id, $mapped->chart_of_accounts_id);
        $this->assertNoDatabaseTenantContext('mapToChartOfAccount() must clear its own internal context wrap after success.');
    }

    public function test_deactivate_succeeds_under_force(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $category = $this->service->create($firm, 'Travel');

        (new TenantContextService)->clearDatabaseTenantContext();

        $deactivated = $this->service->deactivate($firm, $category);

        $this->assertFalse($deactivated->is_active);
        $this->assertNoDatabaseTenantContext();
    }

    public function test_create_blocked_when_module_disabled(): void
    {
        $firm = Firm::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->service->create($firm, 'Travel');
    }

    // ---------------------------------------------------------------
    // Factory correctness proofs
    // ---------------------------------------------------------------

    public function test_expense_category_factory_default_creation_is_safe(): void
    {
        $category = ExpenseCategory::factory()->create();

        $this->assertNotNull($category->id);
        $this->assertNotNull($category->firm_id);

        $persisted = $this->runWithFirmContext(
            $category->firm_id,
            fn () => ExpenseCategory::withoutGlobalScopes()->find($category->id),
        );

        $this->assertNotNull($persisted);
    }

    // ---------------------------------------------------------------
    // Related-model cross-firm mismatch — proven, not assumed.
    // ---------------------------------------------------------------

    public function test_expense_category_row_can_reference_a_different_firms_chart_of_account_a_documented_residual_gap_not_blocked_by_rls(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $accountB = $this->runWithFirmContext($firmB, fn () => ChartOfAccount::factory()->forFirm($firmB)->create());

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA, $accountB) {
            return DB::table('expense_categories')->insertGetId([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'firm_id' => $firmA->id,
                'chart_of_accounts_id' => $accountB->id,
                'name' => 'Travel',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId, 'RLS does NOT block this transitive cross-firm mismatch — a documented, un-closed database-constraint gap (see the migration docblock part (b)), not a guarantee this test claims RLS provides.');

        $persisted = $this->runWithFirmContext(
            $firmA,
            fn () => ExpenseCategory::withoutGlobalScopes()->find($insertedId),
        );

        $this->assertNotNull($persisted);
        $this->assertSame($accountB->id, $persisted->chart_of_accounts_id, 'The row genuinely persisted pointing at firm B\'s own chart_of_accounts row despite its own firm_id being firm A.');
    }

    // ---------------------------------------------------------------
    // Migration down()/up() restoration proofs
    // ---------------------------------------------------------------

    public function test_migration_down_fully_restores_the_pre_checkpoint_state(): void
    {
        $migration = require base_path(self::MIGRATION_PATH);

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'expense_categories'");
            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);

            $policy = DB::selectOne(
                "select 1 from pg_policy where polrelid = 'expense_categories'::regclass and polname = 'expense_categories_tenant_isolation'"
            );
            $this->assertNull($policy);
        } finally {
            $migration->up();
        }
    }

    public function test_migration_round_trip_affects_only_expense_categories(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $sampledPrepared = array_slice($coverage->preparedTables(), 0, 5);
        $otherTables = array_merge($sampledPrepared, [
            'chart_of_accounts', // already forced earlier in this same batch, must remain untouched
            'expenses', // still unprepared at this point in the batch's own migration order, must remain untouched
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
    }

    public function test_compliance_gap_registry_service_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/ComplianceGapRegistryService.php');

        $this->assertEmpty($changed);
    }

    public function test_rls_coverage_mapping_service_and_gap_registry_docs_were_not_modified(): void
    {
        foreach ([
            'app/Services/RowLevelSecurityCoverageMappingService.php',
            'docs/governance/rls-gap-registry.md',
        ] as $reservedPath) {
            $changed = $this->changedOrUntrackedPaths($reservedPath);

            $this->assertEmpty($changed);
        }
    }

    /**
     * This batch's expected file set (§6 of the approved design) — see
     * ChartOfAccountsForceRlsActivationTest::allowedFiles() for the
     * full, authoritative list; duplicated here since all 7 tables
     * land together as ONE combined batch and every one of the 7 new
     * *ForceRlsActivationTest files declares the SAME full expected
     * set rather than only its own table's narrower slice.
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
