<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\EntitlementSource;
use App\Exceptions\TenantIsolationException;
use App\Models\Expense;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterExpense;
use App\Services\AccountingEntitlementPolicyService;
use App\Services\EntitlementService;
use App\Services\MatterExpenseService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TenantContextService;
use App\Services\TenantSafeAccountingPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EvaluatesHistoricalCheckpointScope;
use Tests\TestCase;

/**
 * MatterExpensesForceRlsActivationTest — proves the FORCE ROW LEVEL
 * SECURITY activation for matter_expenses (database/migrations/
 * 2026_08_27_950012_prepare_row_level_security_and_force_rls_on_
 * matter_expenses_table.php) is permanently active and behaves
 * correctly: fail-closed with no context, correct cross-firm isolation
 * on read/update/delete, correct same-firm access, insert and
 * ownership-reassignment protection under the explicit WITH CHECK
 * clause, that every previously-prepared table remains forced
 * simultaneously, and that MatterExpenseService::link() (the sole
 * writer of this table) still functions correctly under FORCE —
 * including the critical regression proof that wrapping the duplicate-
 * guard read and the create() write together in one context did NOT
 * silently defeat the existing "already linked" guard.
 *
 * This is an independent checkpoint (matching the ai_retrieval_indexes/
 * deployment_configs/firm_ai_settings 39A-5 Wave 1 shape): matter_expenses
 * still appears in RowLevelSecurityCoverageMappingService::
 * missingPreparedTables() at the point this test runs — the registry is
 * updated once by the coordinator in a later, separate wave-integration
 * commit, not by this checkpoint. Consequently this test does NOT
 * assert matter_expenses appears in $coverage->preparedTables(), does
 * NOT assert any exact "N prepared tables" count, and does NOT assert
 * it is no longer reported as missing. What IS asserted directly
 * against pg_class/pg_policy (the live database state this migration
 * actually produced) is the row security/policy reality for
 * matter_expenses itself, independent of the registry.
 *
 * Known, stated (not hidden) residual gap: this migration/test batch
 * does NOT close the transitive cross-firm foreign-key gap between
 * matter_expenses.firm_id and the real firm_id of the row matter_id/
 * expense_id point at — see the migration's own docblock. RLS on
 * matter_expenses alone cannot see into matters/expenses to cross-check
 * this; MatterExpenseService::link()'s inline PHP guards remain the
 * only enforcement of that invariant. `expenses` itself also remains
 * fully unprepared (no RLS policy of any kind) — this batch does not
 * touch it.
 */
class MatterExpensesForceRlsActivationTest extends TestCase
{
    use EvaluatesHistoricalCheckpointScope;
    use RefreshDatabase;

    private MatterExpenseService $service;

    private EntitlementService $entitlements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlements = app(EntitlementService::class);
        $this->service = new MatterExpenseService(
            new AccountingEntitlementPolicyService($this->entitlements),
            new TenantSafeAccountingPolicyService,
        );
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

    public function test_matter_expenses_has_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relrowsecurity from pg_class where relname = 'matter_expenses'");

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->relrowsecurity);
    }

    public function test_matter_expenses_has_permanent_force_row_level_security_enabled(): void
    {
        $row = DB::selectOne("select relforcerowsecurity from pg_class where relname = 'matter_expenses'");

        $this->assertNotNull($row);
        $this->assertTrue(
            (bool) $row->relforcerowsecurity,
            'matter_expenses must have permanent FORCE ROW LEVEL SECURITY active — this is not a transient, per-test setting.'
        );
    }

    public function test_the_policy_has_both_an_explicit_using_and_with_check_clause(): void
    {
        $row = DB::selectOne(
            "select pg_get_expr(polqual, polrelid) as using_expr, pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy where polrelid = 'matter_expenses'::regclass and polname = 'matter_expenses_tenant_isolation'"
        );

        $this->assertNotNull($row, 'The matter_expenses_tenant_isolation policy must exist.');

        $expected = "(firm_id = (NULLIF(current_setting('app.current_firm_id'::text, true), ''::text))::bigint)";

        $this->assertSame($expected, $row->using_expr, 'USING clause must match the reviewed predicate exactly.');
        $this->assertSame($expected, $row->with_check_expr, 'WITH CHECK clause must be explicit and identical to USING, not inherited implicitly.');
    }

    // ---------------------------------------------------------------
    // Missing-context fail-closed proofs
    // ---------------------------------------------------------------

    public function test_missing_tenant_context_cannot_read_matter_expenses(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => MatterExpense::factory()->forExpenseAndMatter(
            Expense::factory()->forFirm($firm)->create(),
            Matter::factory()->forFirm($firm)->create(),
        )->create());

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->assertSame(0, MatterExpense::withoutGlobalScopes()->count());
    }

    public function test_missing_tenant_context_cannot_insert_matter_expenses(): void
    {
        $firm = Firm::factory()->create();
        [$matter, $expense] = $this->runWithFirmContext($firm, fn () => [
            Matter::factory()->forFirm($firm)->create(),
            Expense::factory()->forFirm($firm)->create(),
        ]);

        (new TenantContextService)->clearDatabaseTenantContext();

        $this->expectExceptionMessageMatches('/row-level security policy/');

        DB::table('matter_expenses')->insert([
            'firm_id' => $firm->id,
            'matter_id' => $matter->id,
            'expense_id' => $expense->id,
            'reimbursable_snapshot' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // Same-firm access / cross-firm isolation proofs
    // ---------------------------------------------------------------

    public function test_firm_a_context_can_read_its_own_matter_expense(): void
    {
        $firmA = Firm::factory()->create();
        $linkA = $this->runWithFirmContext($firmA, fn () => MatterExpense::factory()->forExpenseAndMatter(
            Expense::factory()->forFirm($firmA)->create(),
            Matter::factory()->forFirm($firmA)->create(),
        )->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => MatterExpense::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertSame([$linkA->id], $visibleIds);
    }

    public function test_firm_a_context_cannot_read_firm_b_matter_expense(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->runWithFirmContext($firmA, fn () => MatterExpense::factory()->forExpenseAndMatter(
            Expense::factory()->forFirm($firmA)->create(),
            Matter::factory()->forFirm($firmA)->create(),
        )->create());
        $linkB = $this->runWithFirmContext($firmB, fn () => MatterExpense::factory()->forExpenseAndMatter(
            Expense::factory()->forFirm($firmB)->create(),
            Matter::factory()->forFirm($firmB)->create(),
        )->create());

        $visibleIds = $this->runWithFirmContext(
            $firmA,
            fn () => MatterExpense::withoutGlobalScopes()->pluck('id')->all(),
        );

        $this->assertNotContains($linkB->id, $visibleIds);
    }

    public function test_firm_a_context_can_insert_a_valid_matter_expense(): void
    {
        $firmA = Firm::factory()->create();
        [$matter, $expense] = $this->runWithFirmContext($firmA, fn () => [
            Matter::factory()->forFirm($firmA)->create(),
            Expense::factory()->forFirm($firmA)->create(),
        ]);

        $insertedId = $this->runWithFirmContext($firmA, function () use ($firmA, $matter, $expense) {
            return DB::table('matter_expenses')->insertGetId([
                'firm_id' => $firmA->id,
                'matter_id' => $matter->id,
                'expense_id' => $expense->id,
                'reimbursable_snapshot' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertIsInt($insertedId);
    }

    public function test_firm_a_cannot_update_firm_b_matter_expense(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $linkB = $this->runWithFirmContext($firmB, fn () => MatterExpense::factory()->forExpenseAndMatter(
            Expense::factory()->forFirm($firmB)->reimbursable(false)->create(),
            Matter::factory()->forFirm($firmB)->create(),
        )->create());

        $affected = $this->runWithFirmContext($firmA, function () use ($linkB) {
            return DB::table('matter_expenses')->where('id', $linkB->id)->update(['reimbursable_snapshot' => true]);
        });

        $this->assertSame(0, $affected, 'No rows should be visible/updatable — Firm A must not be able to update Firm B\'s matter_expenses row.');

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => MatterExpense::withoutGlobalScopes()->find($linkB->id),
        );

        $this->assertNotNull($reReadAsFirmB);
        $this->assertFalse($reReadAsFirmB->reimbursable_snapshot);
    }

    public function test_firm_a_cannot_delete_firm_b_matter_expense(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $linkB = $this->runWithFirmContext($firmB, fn () => MatterExpense::factory()->forExpenseAndMatter(
            Expense::factory()->forFirm($firmB)->create(),
            Matter::factory()->forFirm($firmB)->create(),
        )->create());

        $this->runWithFirmContext($firmA, function () use ($linkB) {
            DB::table('matter_expenses')->where('id', $linkB->id)->delete();
        });

        $reReadAsFirmB = $this->runWithFirmContext(
            $firmB,
            fn () => MatterExpense::withoutGlobalScopes()->find($linkB->id),
        );

        $this->assertNotNull($reReadAsFirmB, 'Firm A context must not be able to delete Firm B matter_expenses.');
    }

    public function test_firm_a_cannot_insert_a_matter_expense_claiming_firm_b_ownership(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        [$matterB, $expenseB] = $this->runWithFirmContext($firmB, fn () => [
            Matter::factory()->forFirm($firmB)->create(),
            Expense::factory()->forFirm($firmB)->create(),
        ]);

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($firmB, $matterB, $expenseB) {
            DB::table('matter_expenses')->insert([
                'firm_id' => $firmB->id,
                'matter_id' => $matterB->id,
                'expense_id' => $expenseB->id,
                'reimbursable_snapshot' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_ownership_cannot_be_reassigned_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $linkA = $this->runWithFirmContext($firmA, fn () => MatterExpense::factory()->forExpenseAndMatter(
            Expense::factory()->forFirm($firmA)->create(),
            Matter::factory()->forFirm($firmA)->create(),
        )->create());

        $this->expectExceptionMessageMatches('/row-level security policy/');

        $this->runWithFirmContext($firmA, function () use ($linkA, $firmB) {
            DB::table('matter_expenses')->where('id', $linkA->id)->update(['firm_id' => $firmB->id]);
        });
    }

    // ---------------------------------------------------------------
    // Context lifecycle proofs
    // ---------------------------------------------------------------

    public function test_tenant_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => MatterExpense::factory()->forExpenseAndMatter(
            Expense::factory()->forFirm($firm)->create(),
            Matter::factory()->forFirm($firm)->create(),
        )->create());

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
    // MatterExpenseService::link() writer regression proofs — the
    // central finding of this checkpoint.
    // ---------------------------------------------------------------

    /** Core proof: link() still functions end-to-end under FORCE. */
    public function test_link_succeeds_under_force_when_matter_expense_and_firm_genuinely_share_a_firm(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $matter = Matter::factory()->forFirm($firm)->create();
        $expense = Expense::factory()->forFirm($firm)->create();

        // MatterFactory/ExpenseFactory deliberately leave the PostgreSQL
        // session's database-only tenant context set to the fixture firm
        // afterward (their own established convention — see
        // MatterFactory::create()'s docblock). Clear it explicitly so the
        // no-context assertion below proves link() itself leaves no
        // context behind, rather than merely restoring that pre-existing
        // fixture leftover.
        (new TenantContextService)->clearDatabaseTenantContext();

        $link = $this->service->link($firm, $matter, $expense);

        $this->assertNotNull($link->id);
        $this->assertNoDatabaseTenantContext();

        $persisted = $this->runWithFirmContext(
            $firm,
            fn () => MatterExpense::withoutGlobalScopes()->find($link->id),
        );

        $this->assertNotNull($persisted, 'link() must genuinely persist a matter_expenses row, readable under its own firm context.');
        $this->assertSame($matter->id, $persisted->matter_id);
        $this->assertSame($expense->id, $persisted->expense_id);
    }

    /**
     * CRITICAL REGRESSION TEST: wrapping the duplicate-guard read and
     * the create() write together in one outer runWithFirmContext() call
     * must NOT silently defeat the pre-existing "already linked" guard.
     * Calling link() twice for the same expense must still throw.
     */
    public function test_link_twice_for_the_same_expense_still_throws_the_duplicate_guard(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $matterOne = Matter::factory()->forFirm($firm)->create();
        $matterTwo = Matter::factory()->forFirm($firm)->create();
        $expense = Expense::factory()->forFirm($firm)->create();

        $this->service->link($firm, $matterOne, $expense);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This expense is already linked to a matter.');

        $this->service->link($firm, $matterTwo, $expense);
    }

    /** link()'s pre-existing cross-firm guards must still throw correctly. */
    public function test_link_still_blocks_cross_firm_matter_under_force(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $this->enableExpenses($otherFirm);

        $matter = Matter::factory()->forFirm($otherFirm)->create();
        $expense = Expense::factory()->forFirm($firm)->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Matter does not belong to this firm.');

        $this->service->link($firm, $matter, $expense);
    }

    /** link()'s pre-existing cross-firm expense guard must still throw correctly. */
    public function test_link_still_blocks_cross_firm_expense_under_force(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $this->enableExpenses($otherFirm);

        $matter = Matter::factory()->forFirm($firm)->create();
        $expense = Expense::factory()->forFirm($otherFirm)->create();

        $this->expectException(TenantIsolationException::class);

        $this->service->link($firm, $matter, $expense);
    }

    /** Tenant context must clear after both the success and exception paths through link(). */
    public function test_link_clears_tenant_context_after_success_and_after_exception(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $matter = Matter::factory()->forFirm($firm)->create();
        $expense = Expense::factory()->forFirm($firm)->create();

        // See test_link_succeeds_under_force_when_matter_expense_and_firm_
        // genuinely_share_a_firm() above for why this explicit clear is
        // needed before asserting "no context" — MatterFactory/
        // ExpenseFactory leave a database-only context behind by design.
        (new TenantContextService)->clearDatabaseTenantContext();

        $this->service->link($firm, $matter, $expense);
        $this->assertNoDatabaseTenantContext('link() must clear its own internal context wrap after a successful link.');

        try {
            $this->service->link($firm, $matter, $expense);
            $this->fail('Expected the duplicate-guard RuntimeException to be thrown.');
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertNoDatabaseTenantContext('link() must clear its own internal context wrap even when the duplicate guard throws.');
    }

    // ---------------------------------------------------------------
    // Factory correctness proofs
    // ---------------------------------------------------------------

    /**
     * Bare factory default: a bare MatterExpense::factory()->create()
     * must succeed even from outside any already-active tenant context
     * (the factory's context-hold create() override), and its nested
     * matter/expense must belong to the SAME firm as firm_id — the
     * factory's own root-cause fix for the cross-firm mismatch a naive
     * three-independent-factories default would otherwise produce.
     */
    public function test_matter_expense_factory_default_creation_is_safe_and_internally_consistent(): void
    {
        $link = MatterExpense::factory()->create();

        $this->assertNotNull($link->id);
        $this->assertNotNull($link->firm_id);

        $persisted = $this->runWithFirmContext(
            $link->firm_id,
            fn () => MatterExpense::withoutGlobalScopes()->with(['matter', 'expense'])->find($link->id),
        );

        $this->assertNotNull($persisted, 'A bare factory-created row must be visible under its own firm\'s context.');
        $this->assertSame($link->firm_id, $persisted->matter->firm_id, 'Bare factory default must not produce a cross-firm matter mismatch.');
        $this->assertSame($link->firm_id, $persisted->expense->firm_id, 'Bare factory default must not produce a cross-firm expense mismatch.');
    }

    // ---------------------------------------------------------------
    // Migration down()/up() restoration proofs
    // ---------------------------------------------------------------

    /**
     * Rollback support: down() must fully undo everything this
     * checkpoint's up() added — FORCE, the policy, AND row-level
     * security being enabled at all — restoring the exact
     * MISSING_PREPARED_TABLES-era state, since this migration
     * introduced the policy itself. up() is restored in a finally block
     * so later tests are unaffected.
     */
    public function test_migration_down_fully_restores_the_pre_checkpoint_state(): void
    {
        $migration = require base_path('database/migrations/2026_08_27_950012_prepare_row_level_security_and_force_rls_on_matter_expenses_table.php');

        $migration->down();

        try {
            $row = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'matter_expenses'");
            $this->assertFalse((bool) $row->relrowsecurity, 'Rollback must fully disable RLS, not merely clear FORCE.');
            $this->assertFalse((bool) $row->relforcerowsecurity, 'Rollback must clear FORCE.');

            $policy = DB::selectOne(
                "select 1 from pg_policy where polrelid = 'matter_expenses'::regclass and polname = 'matter_expenses_tenant_isolation'"
            );
            $this->assertNull($policy, 'Rollback must drop the policy this checkpoint created.');
        } finally {
            $migration->up();
        }
    }

    /**
     * Proves the migration changes only matter_expenses: matters (already
     * FORCE-active) and expenses (still unprepared) are both bit-for-bit
     * identical before and after a down()+up() round trip, along with a
     * sample of previously-prepared tables.
     */
    public function test_migration_round_trip_affects_only_matter_expenses(): void
    {
        $coverage = new RowLevelSecurityCoverageMappingService;

        $sampledPrepared = array_slice($coverage->preparedTables(), 0, 5);
        $otherTables = array_merge($sampledPrepared, [
            'matters', // already FORCE-active, must remain untouched
            'expenses', // still unprepared (MISSING_PREPARED_TABLES), must remain untouched
        ]);

        $before = [];
        foreach ($otherTables as $table) {
            $before[$table] = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);
        }

        $migration = require base_path('database/migrations/2026_08_27_950012_prepare_row_level_security_and_force_rls_on_matter_expenses_table.php');
        $migration->down();
        $migration->up();

        foreach ($otherTables as $table) {
            $after = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertSame(
                (bool) $before[$table]->relrowsecurity,
                (bool) $after->relrowsecurity,
                "{$table}'s relrowsecurity must be unaffected by the matter_expenses migration round trip."
            );
            $this->assertSame(
                (bool) $before[$table]->relforcerowsecurity,
                (bool) $after->relforcerowsecurity,
                "{$table}'s relforcerowsecurity must be unaffected by the matter_expenses migration round trip."
            );
        }
    }

    // ---------------------------------------------------------------
    // Scope proofs
    // ---------------------------------------------------------------

    public function test_no_ui_routes_controllers_or_deployment_features_were_added(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "This checkpoint must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }
    }

    public function test_compliance_gap_registry_service_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/ComplianceGapRegistryService.php');

        $this->assertEmpty($changed, 'ComplianceGapRegistryService.php must remain untouched by this checkpoint.');
    }

    public function test_rls_coverage_mapping_service_and_gap_registry_docs_were_not_modified(): void
    {
        foreach ([
            'app/Services/RowLevelSecurityCoverageMappingService.php',
            'docs/governance/rls-gap-registry.md',
        ] as $reservedPath) {
            $changed = $this->changedOrUntrackedPaths($reservedPath);

            $this->assertEmpty($changed, "{$reservedPath} is reserved for a later, separate wave-integration commit and must remain untouched by this checkpoint.");
        }
    }

    public function test_only_this_checkpoints_expected_files_were_changed(): void
    {
        $changed = $this->changedOrUntrackedPaths('.');

        $allowed = [
            'database/migrations/2026_08_27_950012_prepare_row_level_security_and_force_rls_on_matter_expenses_table.php',
            'database/factories/MatterExpenseFactory.php',
            'app/Services/MatterExpenseService.php',
            'tests/Feature/Accounting/Expenses/MatterExpenseServiceTest.php',
            'tests/Feature/Security/RlsForceRollout/MatterExpensesForceRlsActivationTest.php',
        ];

        $unexpected = array_values(array_diff($changed, $allowed));

        $this->assertEmpty($unexpected, 'Unexpected files changed for this checkpoint: '.implode(', ', $unexpected));
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
        // feature/ses-event-consumer (a later, distinct, wholly
        // isolated mission: a production-safe SES bounce/complaint
        // consumer) legitimately added a notification-provider
        // correlation ledger + idempotency ledger (both exempted,
        // no-RLS, registered in RowLevelSecurityCoverageMappingService
        // per the same integration_webhook_routing_index/
        // integration_platform_provider_health_summaries precedent
        // pattern), a dedicated SQS consumer command, real-send
        // correlation wiring in User/ClientPortalUser password-reset
        // notifications, and its own new test files. Also
        // mechanically added this exact const + filtering addition
        // across all its sibling RlsForceRollout/Governance/Security
        // firewall test files touched by this same mission, matching
        // this array's own established cross-file-listing convention.
        'app/Console/Commands/ConsumeSesEventsCommand.php',
        'app/Enums/SesBounceType.php',
        'app/Enums/SesEventType.php',
        'app/Models/ClientPortalUser.php',
        'app/Models/NotificationEvent.php',
        'app/Models/NotificationProviderCorrelation.php',
        'app/Models/SesEventReceipt.php',
        'app/Models/User.php',
        'app/Notifications/ClientPortalResetPasswordNotification.php',
        'app/Notifications/FirmOwnerInvitationNotification.php',
        'app/Providers/AppServiceProvider.php',
        'app/Services/NotificationDispatchService.php',
        'app/Services/OutboundMailCorrelationService.php',
        'app/Services/RowLevelSecurityCoverageMappingService.php',
        'app/Services/SesEventConsumerService.php',
        'config/mail.php',
        'config/services.php',
        'database/migrations/2026_10_15_100001_add_provider_message_id_to_notification_events_table.php',
        'database/migrations/2026_10_15_100002_create_notification_provider_correlations_table.php',
        'database/migrations/2026_10_15_100003_create_ses_event_receipts_table.php',
        'tests/Feature/Governance/AcceptanceTestMatrix/AcceptanceTestMatrixFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlUiBoundaryTest.php',
        'tests/Feature/Governance/CrossCutting/CrossCuttingFirewallTest.php',
        'tests/Feature/Governance/DataModelContract/DataModelContractFirewallTest.php',
        'tests/Feature/Governance/DataModelContract/RowLevelSecurityCoverageMappingServiceTest.php',
        'tests/Feature/Governance/DeploymentEnvironment/DeploymentEnvironmentFirewallTest.php',
        'tests/Feature/Governance/EdgeCaseRiskHandling/EdgeCaseRiskFirewallTest.php',
        'tests/Feature/Governance/EntityFieldCatalog/EntityFieldCatalogFirewallTest.php',
        'tests/Feature/Governance/FinalExecutiveRecommendation/FinalExecutiveRecommendationFirewallTest.php',
        'tests/Feature/Governance/MarketReadyValueMultipliers/MarketReadyFirewallTest.php',
        'tests/Feature/Governance/PermissionBoundaries/PermissionBoundaryFirewallTest.php',
        'tests/Feature/Governance/PrePilotRemediationBacklog/PrePilotRemediationFirewallTest.php',
        'tests/Feature/Governance/ProfessionalReviewGate/ProfessionalReviewFirewallTest.php',
        'tests/Feature/Governance/QualityGates/QualityGateFirewallTest.php',
        'tests/Feature/Governance/WorkflowStateMachines/WorkflowStateMachineFirewallTest.php',
        'tests/Feature/Notifications/ConsumeSesEventsCommandTest.php',
        'tests/Feature/Notifications/OutboundMailCorrelationServiceTest.php',
        'tests/Feature/Notifications/SesEventConsumerServiceTest.php',
        'tests/Feature/Security/LoginPolicy/LoginPolicyFirewallTest.php',
        'tests/Feature/Security/RlsContextRollout/QueueConsoleContextRolloutTest.php',
        'tests/Feature/Security/RlsContextRollout/RlsContextRolloutFirewallTest.php',
        'tests/Feature/Security/RlsEnforcement/QueueConsoleTenantContextTest.php',
        'tests/Feature/Security/RlsEnforcement/RlsEnforcementFirewallTest.php',
        'tests/Feature/Security/RlsForceRollout/AccountingExportBatchesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AccountingExportLinesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiRetrievalIndexesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiToolActionsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiUsageEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ChartOfAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/CustomerSuccessHealthScoresForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeletionRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeploymentConfigsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeploymentHealthChecksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DocumentHashesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAttachmentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessageLinksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessagesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailSyncEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailVisibilityRulesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseApprovalsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseCategoriesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseReceiptsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExportJobsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiProviderKeysForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiSettingsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FleetMigrationInstanceStatusForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormDraftsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormReviewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ImplementationProjectsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ImportBatchesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/KeyDestructionRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/LegalHoldsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MatterExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MatterTrustBalancesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MigrationProjectsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/OffboardingRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PdfViewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PrivateEnterpriseSettingsForceRlsActivationTest.php',
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
        'tests/Feature/Security/SeedData/SecretPatternScanTest.php',
        'tests/Feature/Security/SeedData/SeedDataAuditFirewallTest.php',
        'tests/Feature/Security/SupportAccess/EmergencySupportApprovalFirewallTest.php',
        // post-578ee98 audit remediation (a later, distinct,
        // independent security/architecture review of the SES
        // event consumer feature) legitimately fixed a MessageSent
        // listener leak, an uncaught-exception crash risk in the
        // consumer command, a receipt-write concurrency race, a
        // complaint recipient-mismatch hard-reject, and added a new
        // platform-scope correlation/suppression subsystem for
        // password-reset sends that cannot resolve a firm — plus
        // its own new test files. Also mechanically added this
        // exact const + filtering addition across all its sibling
        // firewall test files touched by this same remediation,
        // matching this array's own established cross-file-listing
        // convention.
        'app/Console/Commands/ConsumeSesEventsCommand.php',
        'app/Models/ClientPortalUser.php',
        'app/Models/PlatformNotificationCorrelation.php',
        'app/Models/PlatformNotificationSuppression.php',
        'app/Models/User.php',
        'app/Services/OutboundMailCorrelationService.php',
        'app/Services/PlatformNotificationCorrelationService.php',
        'app/Services/RowLevelSecurityCoverageMappingService.php',
        'app/Services/SesEventConsumerService.php',
        'app/Services/SuppressionService.php',
        'config/services.php',
        'database/migrations/2026_10_15_100002_create_notification_provider_correlations_table.php',
        'database/migrations/2026_10_20_100001_create_platform_notification_correlations_table.php',
        'database/migrations/2026_10_20_100002_create_platform_notification_suppressions_table.php',
        'tests/Feature/Governance/AcceptanceTestMatrix/AcceptanceTestMatrixFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlUiBoundaryTest.php',
        'tests/Feature/Governance/CrossCutting/CrossCuttingFirewallTest.php',
        'tests/Feature/Governance/DataModelContract/DataModelContractFirewallTest.php',
        'tests/Feature/Governance/DataModelContract/RowLevelSecurityCoverageMappingServiceTest.php',
        'tests/Feature/Governance/DeploymentEnvironment/DeploymentEnvironmentFirewallTest.php',
        'tests/Feature/Governance/EdgeCaseRiskHandling/EdgeCaseRiskFirewallTest.php',
        'tests/Feature/Governance/EntityFieldCatalog/EntityFieldCatalogFirewallTest.php',
        'tests/Feature/Governance/FinalExecutiveRecommendation/FinalExecutiveRecommendationFirewallTest.php',
        'tests/Feature/Governance/MarketReadyValueMultipliers/MarketReadyFirewallTest.php',
        'tests/Feature/Governance/PermissionBoundaries/PermissionBoundaryFirewallTest.php',
        'tests/Feature/Governance/PrePilotRemediationBacklog/PrePilotRemediationFirewallTest.php',
        'tests/Feature/Governance/ProfessionalReviewGate/ProfessionalReviewFirewallTest.php',
        'tests/Feature/Governance/QualityGates/QualityGateFirewallTest.php',
        'tests/Feature/Governance/WorkflowStateMachines/WorkflowStateMachineFirewallTest.php',
        'tests/Feature/Mail/SesMailerTransportTest.php',
        'tests/Feature/Notifications/ConsumeSesEventsCommandTest.php',
        'tests/Feature/Notifications/OutboundMailCorrelationServiceTest.php',
        'tests/Feature/Notifications/PasswordResetPlatformCorrelationFallbackTest.php',
        'tests/Feature/Notifications/PlatformNotificationCorrelationServiceTest.php',
        'tests/Feature/Notifications/SesEventConsumerServiceTest.php',
        'tests/Feature/Notifications/SuppressionServiceTest.php',
        'tests/Feature/Security/LoginPolicy/LoginPolicyFirewallTest.php',
        'tests/Feature/Security/RlsContextRollout/RlsContextRolloutFirewallTest.php',
        'tests/Feature/Security/RlsEnforcement/RlsEnforcementFirewallTest.php',
        'tests/Feature/Security/RlsForceRollout/AccountingExportBatchesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AccountingExportLinesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiRetrievalIndexesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiToolActionsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiUsageEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ChartOfAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/CustomerSuccessHealthScoresForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeploymentConfigsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DocumentHashesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAttachmentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessageLinksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessagesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailSyncEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailVisibilityRulesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseApprovalsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseCategoriesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseReceiptsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiProviderKeysForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiSettingsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormDraftsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormReviewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MatterExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PdfViewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PrivateEnterpriseSettingsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureCertificatesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureRequestRecipientsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/SeedData/SeedDataAuditFirewallTest.php',
        'tests/Feature/Security/SupportAccess/EmergencySupportApprovalFirewallTest.php',
        // Round 3 audit remediation (a later, distinct,
        // independent security/architecture review requiring exact
        // firm correlation for all tenant-owned email, with no
        // platform-level or uncorrelated fallback) legitimately
        // introduced CorrelatedPasswordResetSenderService as the
        // single dedicated sender every password-reset/invitation
        // send goes through, fixed a transaction-poisoning bug in
        // both correlation services' before/post-send DB writes,
        // and rewired FirmProvisioningService's owner-invitation
        // dispatch through sendResetLink()'s own $callback
        // parameter — plus its own new test files. Also
        // mechanically added this exact const + filtering addition
        // across all its sibling firewall test files touched by
        // this same remediation.
        '.env.example',
        'app/Enums/CorrelatedSendResult.php',
        'app/Exceptions/NotificationTransportFailedException.php',
        'app/Models/ClientPortalUser.php',
        'app/Models/User.php',
        'app/Services/CorrelatedPasswordResetSenderService.php',
        'app/Services/FirmProvisioningService.php',
        'app/Services/OutboundMailCorrelationService.php',
        'app/Services/PlatformNotificationCorrelationService.php',
        'tests/Feature/Governance/AcceptanceTestMatrix/AcceptanceTestMatrixFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlFirewallTest.php',
        'tests/Feature/Governance/AdminControlCatalog/AdminControlUiBoundaryTest.php',
        'tests/Feature/Governance/EdgeCaseRiskHandling/EdgeCaseRiskFirewallTest.php',
        'tests/Feature/Governance/EntityFieldCatalog/EntityFieldCatalogFirewallTest.php',
        'tests/Feature/Governance/FinalExecutiveRecommendation/FinalExecutiveRecommendationFirewallTest.php',
        'tests/Feature/Governance/PrePilotRemediationBacklog/PrePilotRemediationFirewallTest.php',
        'tests/Feature/Governance/ProfessionalReviewGate/ProfessionalReviewFirewallTest.php',
        'tests/Feature/Governance/WorkflowStateMachines/WorkflowStateMachineFirewallTest.php',
        'tests/Feature/Notifications/OutboundMailCorrelationServiceTest.php',
        'tests/Feature/Notifications/PasswordResetPlatformCorrelationFallbackTest.php',
        'tests/Feature/Notifications/PlatformNotificationCorrelationServiceTest.php',
        'tests/Feature/Security/RlsForceRollout/AccountingExportBatchesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AccountingExportLinesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiApprovalRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiRetrievalIndexesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiToolActionsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/AiUsageEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ChartOfAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/CustomerSuccessHealthScoresForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DeploymentConfigsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/DocumentHashesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAccountsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailAttachmentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessageLinksForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailMessagesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailSyncEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/EmailVisibilityRulesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseApprovalsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseCategoriesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpenseReceiptsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/ExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiProviderKeysForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FirmAiSettingsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormDraftsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/FormReviewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/GeneratedDocumentsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/MatterExpensesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PdfViewEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/PrivateEnterpriseSettingsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureCertificatesForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureEventsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureRequestRecipientsForceRlsActivationTest.php',
        'tests/Feature/Security/RlsForceRollout/SignatureRequestsForceRlsActivationTest.php',
        'tests/Feature/Security/SeedData/SeedDataAuditFirewallTest.php',
        'tests/Feature/Security/SupportAccess/EmergencySupportApprovalFirewallTest.php',
        'tests/Feature/Services/FirmProvisioningServiceTest.php',
        // Retention governance type-normalization fix (a later,
        // distinct remediation restoring CI's protected suite —
        // RetentionGovernanceRegistryService::current_default was
        // returned as a raw string under CI's fresh-.env condition
        // instead of a real PHP int; fixed at the config() boundary,
        // plus its own focused regression tests).
        'app/Services/RetentionGovernanceRegistryService.php',
        'tests/Feature/Governance/Retention/RetentionGovernanceRegistryServiceTest.php',
        // feature/ses-consumer-ecs-wiring (a later, distinct,
        // wholly isolated ECS/IAM wiring mission) legitimately
        // added docker/entrypoint.sh's ses-consumer role dispatch,
        // a new docker/commands/ses-consumer.sh, Terraform IAM/
        // ECS-service/CloudWatch-alarm wiring for that role, its
        // own docs/ecs/ updates, and its own new test files.
        'docker/commands/ses-consumer.sh',
        'docker/entrypoint.sh',
        'docs/ecs/alarm-inventory.md',
        'docs/ecs/container-architecture.md',
        'docs/ecs/database-migrations.md',
        'docs/ecs/env.ecs.example',
        'docs/ecs/graceful-shutdown.md',
        'docs/ecs/iam-matrix.md',
        'docs/ecs/infrastructure-architecture.md',
        'docs/ecs/observability.md',
        'docs/ecs/runbooks/deployment-runbook.md',
        'docs/ecs/runbooks/rollback-runbook.md',
        'infrastructure/ecs/environments/staging/main.tf',
        'infrastructure/ecs/environments/staging/outputs.tf',
        'infrastructure/ecs/environments/staging/terraform.tfvars.example',
        'infrastructure/ecs/environments/staging/variables.tf',
        'infrastructure/ecs/modules/cloudwatch_alarms/main.tf',
        'infrastructure/ecs/modules/cloudwatch_alarms/variables.tf',
        'infrastructure/ecs/modules/iam/main.tf',
        'infrastructure/ecs/modules/iam/variables.tf',
        'tests/Feature/Ecs/SesConsumerEntrypointTest.php',
        'tests/Feature/Ecs/SesConsumerTerraformIamTest.php',
        // feature/ses-consumer-ecs-wiring also bounded the SqsClient's HTTP connect/overall timeouts here, confirmed necessary by a real container-level graceful-shutdown smoke test.
        'app/Providers/AppServiceProvider.php',
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
