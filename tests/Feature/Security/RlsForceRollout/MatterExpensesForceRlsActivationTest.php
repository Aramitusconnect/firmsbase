<?php

namespace Tests\Feature\Security\RlsForceRollout;

use App\Enums\EntitlementSource;
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
    use RefreshDatabase;

    private MatterExpenseService $service;

    private EntitlementService $entitlements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlements = app(EntitlementService::class);
        $this->service = new MatterExpenseService(
            new AccountingEntitlementPolicyService($this->entitlements),
            new TenantSafeAccountingPolicyService(),
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

        $this->expectException(\App\Exceptions\TenantIsolationException::class);

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
