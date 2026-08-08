<?php

namespace Tests\Feature\Accounting\ChartOfAccounts;

use App\Enums\ChartOfAccountType;
use App\Enums\EntitlementSource;
use App\Models\Firm;
use App\Services\AccountingEntitlementPolicyService;
use App\Services\ChartOfAccountsService;
use App\Services\EntitlementService;
use App\Services\ExpenseCategoryService;
use App\Services\TenantSafeAccountingPolicyService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpenseCategoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExpenseCategoryService $service;

    private ChartOfAccountsService $coaService;

    private EntitlementService $entitlements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlements = app(EntitlementService::class);
        $this->service = new ExpenseCategoryService(
            new AccountingEntitlementPolicyService($this->entitlements),
            new TenantSafeAccountingPolicyService,
        );
        $this->coaService = new ChartOfAccountsService(new AccountingEntitlementPolicyService($this->entitlements));
    }

    private function enableExpenses(Firm $firm): void
    {
        $this->entitlements->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);
    }

    public function test_category_is_created_with_non_nullable_firm_id(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);

        $category = $this->service->create($firm, 'Office Supplies');

        $this->assertNotNull($category->firm_id);
        $this->assertSame($firm->id, $category->firm_id);
    }

    /**
     * Correction #3: expense_categories.firm_id is non-nullable at the
     * schema level — no platform-global category can be created.
     */
    public function test_expense_categories_firm_id_column_is_non_nullable(): void
    {
        $this->expectException(QueryException::class);

        DB::table('expense_categories')->insert([
            'uuid' => (string) Str::uuid7(),
            'firm_id' => null,
            'name' => 'Should Fail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_category_can_be_mapped_to_chart_of_account(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $category = $this->service->create($firm, 'Travel');
        $account = $this->coaService->create($firm, '6100', 'Travel Expense', ChartOfAccountType::Expense);

        $mapped = $this->service->mapToChartOfAccount($firm, $category, $account);

        $this->assertSame($account->id, $mapped->chart_of_accounts_id);
    }

    public function test_creation_blocked_when_module_disabled(): void
    {
        $firm = Firm::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->service->create($firm, 'Office Supplies');
    }

    /**
     * FirmsVault staging follow-up addition ("Application Completion —
     * Catalogs + Firm-Owned Reference Data") — update()/reactivate().
     */
    public function test_update_renames_a_category(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $category = $this->service->create($firm, 'Old Name');

        $updated = $this->service->update($firm, $category, 'New Name');

        $this->assertSame('New Name', $updated->name);
    }

    public function test_update_rejects_a_duplicate_name_within_the_same_firm(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $this->service->create($firm, 'Filing Fees');
        $category = $this->service->create($firm, 'Court Costs');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->update($firm, $category, 'Filing Fees');
    }

    public function test_deactivate_then_reactivate_round_trips_is_active(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $category = $this->service->create($firm, 'Travel');

        $deactivated = $this->service->deactivate($firm, $category);
        $this->assertFalse($deactivated->is_active);

        $reactivated = $this->service->reactivate($firm, $category);
        $this->assertTrue($reactivated->is_active);
    }
}
