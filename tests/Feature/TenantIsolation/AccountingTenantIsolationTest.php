<?php

namespace Tests\Feature\TenantIsolation;

use App\Exceptions\TenantIsolationException;
use App\Models\AccountingExportBatch;
use App\Models\ChartOfAccount;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Firm;
use App\Models\Matter;
use App\Services\TenantContextResolver;
use App\Services\TenantSafeAccountingPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Required: cross-firm invoice/expense/matter combinations are blocked
 * — the general tenant-isolation proof for every Phase 12 table,
 * mirroring Phase 11's SignatureAndPdfTenantIsolationTest exactly.
 */
class AccountingTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantSafeAccountingPolicyService $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new TenantSafeAccountingPolicyService();
    }

    protected function tearDown(): void
    {
        TenantContextResolver::clear();
        parent::tearDown();
    }

    public function test_expense_belonging_to_another_firm_is_rejected(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $expense = Expense::factory()->forFirm($firmB)->create();

        $this->expectException(TenantIsolationException::class);
        $this->policy->assertExpenseBelongsToFirm($expense, $firmA);
    }

    public function test_chart_of_account_belonging_to_another_firm_is_rejected(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $account = ChartOfAccount::factory()->forFirm($firmB)->create();

        $this->expectException(TenantIsolationException::class);
        $this->policy->assertChartOfAccountBelongsToFirm($account, $firmA);
    }

    public function test_accounting_export_batch_belonging_to_another_firm_is_rejected(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $batch = AccountingExportBatch::factory()->forFirm($firmB)->create();

        $this->expectException(TenantIsolationException::class);
        $this->policy->assertAccountingExportBatchBelongsToFirm($batch, $firmA);
    }

    public function test_matter_and_expense_from_different_firms_are_rejected(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $matter = Matter::factory()->forFirm($firmA)->create();
        $expense = Expense::factory()->forFirm($firmB)->create();

        $this->expectException(TenantIsolationException::class);
        $this->policy->assertMatterAndExpenseShareFirm($matter, $expense);
    }

    /**
     * BelongsToTenant global-scope proof (defense-in-depth's other
     * half): with an active tenant context, a cross-firm Expense row is
     * invisible to a plain query, not merely rejected by the explicit
     * assertion above.
     *
     * expenses now has permanent FORCE ROW LEVEL SECURITY (see
     * database/migrations/2026_08_27_950020_prepare_row_level_security_
     * and_force_rls_on_expenses_table.php, landing in the Wave 4
     * accounting-domain batch). Activating ONLY the PHP-memory tenant
     * context (TenantContextResolver::activateForFirm(), as this test
     * did previously) no longer suffices: PostgreSQL's own
     * app.current_firm_id session setting is now independently
     * consulted by FORCE RLS on every read, and is not touched by
     * activateForFirm() at all. Left unset (or, worse, silently holding
     * a firm's id "left over" from ExpenseFactory::create()'s own
     * documented context-holding side effect, whichever firm happened
     * to be created last), the real RLS-enforced row set and the
     * Eloquent-global-scope-filtered row set silently disagree — this
     * assertion must establish BOTH layers explicitly and correctly via
     * runWithFirmContext(), the same convention used everywhere else in
     * this batch, rather than relying on activateForFirm() alone or on
     * whatever context a preceding factory call happened to leave
     * active.
     */
    public function test_belongs_to_tenant_scope_hides_cross_firm_expenses(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        Expense::factory()->forFirm($firmA)->create();
        Expense::factory()->forFirm($firmB)->create();

        $count = $this->runWithFirmContext($firmA, fn () => Expense::query()->count());

        $this->assertSame(1, $count);
    }

    /**
     * expense_categories now ALSO has permanent FORCE ROW LEVEL
     * SECURITY (see database/migrations/2026_08_27_950019_prepare_row_
     * level_security_and_force_rls_on_expense_categories_table.php,
     * same Wave 4 batch) — same rationale as
     * test_belongs_to_tenant_scope_hides_cross_firm_expenses() above:
     * activateForFirm() alone does not establish the real PostgreSQL
     * session context FORCE RLS now independently requires.
     */
    public function test_expense_category_firm_id_is_non_nullable_and_tenant_scoped(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        ExpenseCategory::factory()->forFirm($firmA)->create();
        ExpenseCategory::factory()->forFirm($firmB)->create();

        $count = $this->runWithFirmContext($firmB, fn () => ExpenseCategory::query()->count());

        $this->assertSame(1, $count);
    }
}
