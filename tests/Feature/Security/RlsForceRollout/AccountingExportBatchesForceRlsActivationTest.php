<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\AccountingExportBatchStatus;
use App\Enums\AccountingExportTarget;
use App\Enums\EntitlementSource;
use App\Models\AccountingExportBatch;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\AccountingEntitlementPolicyService;
use App\Services\AccountingExportBatchService;
use App\Services\EntitlementService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AccountingExportBatchesForceRlsActivationTest — proves the FORCE ROW
 * LEVEL SECURITY activation for accounting_export_batches (database/
 * migrations/2026_08_27_950023_prepare_row_level_security_and_force_
 * rls_on_accounting_export_batches_table.php) is permanently active and
 * behaves correctly. This is the SIXTH checkpoint of this batch's
 * 7-table combined Wave 4 accounting/expense-domain activation — see
 * ChartOfAccountsForceRlsActivationTest's own docblock for the full
 * combined-batch rationale. Structurally the least entangled of the 7
 * (no in-scope FK dependency of its own) — grouped here for batching
 * convenience, not a hard dependency.
 *
 * Known, stated (not hidden) residual gap: this migration does not
 * assert requested_by_firm_user_id belongs to the same firm as the
 * batch (the actor-attribution gap, see the migration's own docblock,
 * part (b)) — this table has no in-scope parent FK of its own, so
 * there is no "related-model cross-firm-mismatch" test of the shape
 * used by this batch's other checkpoints.
 */
class AccountingExportBatchesForceRlsActivationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_27_950023_prepare_row_level_security_and_force_rls_on_accounting_export_batches_table.php';

    private AccountingExportBatchService $service;

    private EntitlementService $entitlements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlements = app(EntitlementService::class);
        $this->service = new AccountingExportBatchService(new AccountingEntitlementPolicyService($this->entitlements));
    }

    private function enableExpenses(Firm $firm): void
    {
        $this->entitlements->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);
    }

    private function rowAttributes(Firm $firm, FirmUser $requester): array
    {
        return [
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'firm_id' => $firm->id,
            'export_target' => AccountingExportTarget::QuickbooksOnline->value,
            'status' => AccountingExportBatchStatus::Requested->value,
            'requested_by_firm_user_id' => $requester->id,
            'date_range_start' => now()->subDays(30)->toDateString(),
            'date_range_end' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
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

    public function test_accounting_export_batches_is_discovered_by_the_forced_tables_registry(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $this->assertContains('accounting_export_batches', $coverage->forcedTables());
    }

    public function test_accounting_export_batches_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'accounting_export_batches'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_accounting_export_batches_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'accounting_export_batches'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relforcerowsecurity);
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'accounting_export_batches'::regclass and polname = 'accounting_export_batches_tenant_isolation'"
        );

        $this->assertNotNull($row);

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr);
        $this->assertSame($expected, $row->with_check_expr);
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_accounting_export_batches(): void
    {
        $firm = Firm::factory()->create();
        AccountingExportBatch::factory()->forFirm($firm)->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, AccountingExportBatch::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_insert_accounting_export_batches(): void
    {
        $firm = Firm::factory()->create();
        $requester = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create());

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('accounting_export_batches')->insert($this->rowAttributes($firm, $requester));
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_accounting_export_batch(): void
    {
        $firmA = Firm::factory()->create();
        $batchA = $this->runWithFirmContext($firmA, fn () => AccountingExportBatch::factory()->forFirm($firmA)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => AccountingExportBatch::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$batchA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_accounting_export_batch(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->runWithFirmContext($firmA, fn () => AccountingExportBatch::factory()->forFirm($firmA)->create());
        $batchB = $this->runWithFirmContext($firmB, fn () => AccountingExportBatch::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => AccountingExportBatch::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($batchB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_accounting_export_batch(): void
    {
        $firmA = Firm::factory()->create();
        $requester = $this->runWithFirmContext($firmA, fn () => FirmUser::factory()->forFirm($firmA)->create());

        $insertedId = $this->runWithFirmContext($firmA, fn () => DB::table('accounting_export_batches')->insertGetId($this->rowAttributes($firmA, $requester)));

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_accounting_export_batch(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $batchB = $this->runWithFirmContext($firmB, fn () => AccountingExportBatch::factory()->forFirm($firmB)->status(AccountingExportBatchStatus::Requested)->create());

        $affected = $this->runWithFirmContext($firmA, function () use ($batchB) {
            return DB::table('accounting_export_batches')->where('id', $batchB->id)->update(['status' => AccountingExportBatchStatus::Failed->value]);
        });

        $this->assertSame(0, $affected);

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => AccountingExportBatch::withoutGlobalScopes()->find($batchB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertSame(AccountingExportBatchStatus::Requested, $reReadAsFirmB->status);
    }

    public function test_firm_a_cannot_delete_firm_b_accounting_export_batch(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $batchB = $this->runWithFirmContext($firmB, fn () => AccountingExportBatch::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($batchB) {
            DB::table('accounting_export_batches')->where('id', $batchB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => AccountingExportBatch::withoutGlobalScopes()->find($batchB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B accounting_export_batches.');
    }

    public function test_firm_a_cannot_insert_an_accounting_export_batch_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $requesterB = $this->runWithFirmContext($firmB, fn () => FirmUser::factory()->forFirm($firmB)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, fn () => DB::table('accounting_export_batches')->insert($this->rowAttributes($firmB, $requesterB)));
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $batchA = $this->runWithFirmContext($firmA, fn () => AccountingExportBatch::factory()->forFirm($firmA)->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($batchA, $firmB) {
            DB::table('accounting_export_batches')->where('id', $batchA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => AccountingExportBatch::factory()->forFirm($firm)->create());

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
    // AccountingExportBatchService writer regression proofs
    // ---------------------------------------------------------------

    public function test_request_succeeds_under_force_and_clears_context(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $requester = FirmUser::factory()->forFirm($firm)->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $batch = $this->service->request($firm, $requester, now()->subDays(30), now());

        $this->assertSame(AccountingExportBatchStatus::Requested, $batch->status);
        $this->assertNoDatabaseTenantContext('request() must clear its own internal context wrap after success.');
    }

    public function test_request_blocked_status_branch_succeeds_under_force_when_module_disabled(): void
    {
        $firm = Firm::factory()->create();
        $requester = FirmUser::factory()->forFirm($firm)->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $batch = $this->service->request($firm, $requester, now()->subDays(30), now());

        $this->assertSame(AccountingExportBatchStatus::Blocked, $batch->status);
        $this->assertNoDatabaseTenantContext('request()\'s Blocked-status branch must ALSO clear its own internal context wrap.');
    }

    public function test_lifecycle_transitions_succeed_under_force(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $requester = FirmUser::factory()->forFirm($firm)->create();

        // FirmUserFactory::create() deliberately leaves the PostgreSQL
        // session's app.current_firm_id set to the just-created row's own
        // firm (see FirmUserFactory::create()'s own docblock) — the exact
        // same reason every sibling "*_succeeds_under_force_and_clears_
        // context" test in this file clears it explicitly before invoking
        // the service under test, rather than asserting a false negative
        // caused by this leftover fixture-setup context instead of by the
        // service's own runWithFirmContext() wrap.
        (new TenantContextService)->clearDatabaseTenantContext();

        $batch = $this->service->request($firm, $requester, now()->subDays(30), now());
        $batch = $this->service->markInProgress($batch);
        $this->assertSame(AccountingExportBatchStatus::InProgress, $batch->status);

        $batch = $this->service->markCompleted($batch);
        $this->assertSame(AccountingExportBatchStatus::Completed, $batch->status);
        $this->assertNotNull($batch->completed_at);
        $this->assertNoDatabaseTenantContext();
    }

    // ---------------------------------------------------------------
    // Factory correctness proofs
    // ---------------------------------------------------------------

    public function test_accounting_export_batch_factory_default_creation_is_safe_and_internally_consistent(): void
    {
        $batch = AccountingExportBatch::factory()->create();

        $this->assertNotNull($batch->id);
        $this->assertNotNull($batch->firm_id);

        $persisted = $this->runWithFirmContext(
            $batch->firm_id,
            fn () => AccountingExportBatch::withoutGlobalScopes()->with('requestedBy')->find($batch->id),
        );

        $this->assertNotNull($persisted);
        $this->assertSame($batch->firm_id, $persisted->requestedBy->firm_id, 'Bare factory default must not produce a cross-firm requested-by firm_user mismatch.');
    }

    // ---------------------------------------------------------------
    // Migration down()/up() restoration proofs
    // ---------------------------------------------------------------

    public function test_migration_down_fully_restores_the_pre_checkpoint_state(): void
    {
        $migration = require base_path(self::MIGRATION_PATH);

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'accounting_export_batches'");
            $this->assertFalse((bool) $row->relrowsecurity);
            $this->assertFalse((bool) $row->relforcerowsecurity);

            $policy = DB::selectOne(
                "select 1 from pg_policy where polrelid = 'accounting_export_batches'::regclass and polname = 'accounting_export_batches_tenant_isolation'"
            );
            $this->assertNull($policy);
        } finally {
            $migration->up();
        }
    }

    public function test_migration_round_trip_affects_only_accounting_export_batches(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $sampledPrepared = array_slice($coverage->preparedTables(), 0, 5);
        $otherTables = array_merge($sampledPrepared, [
            'expenses', // already forced earlier in this same batch, must remain untouched
            'accounting_export_lines', // still unprepared at this point in the batch's own migration order, must remain untouched
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
            $this->assertEmpty($this->changedOrUntrackedPaths($relativeDir));
        }
    }

    public function test_compliance_gap_registry_service_was_not_modified(): void
    {
        $this->assertEmpty($this->changedOrUntrackedPaths('app/Services/ComplianceGapRegistryService.php'));
    }

    public function test_rls_coverage_mapping_service_and_gap_registry_docs_were_not_modified(): void
    {
        foreach ([
            'app/Services/RowLevelSecurityCoverageMappingService.php',
            'docs/governance/rls-gap-registry.md',
        ] as $reservedPath) {
            $this->assertEmpty($this->changedOrUntrackedPaths($reservedPath));
        }
    }

    /**
     * This batch's expected file set (§6 of the approved design) — see
     * ChartOfAccountsForceRlsActivationTest::allowedFiles() for the
     * full, authoritative list; duplicated here since all 7 tables
     * land together as ONE combined batch.
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
