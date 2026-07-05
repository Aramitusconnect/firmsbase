<?php

namespace Tests\Feature\Accounting\ChartOfAccounts;

use App\Enums\ChartOfAccountType;
use App\Enums\EntitlementSource;
use App\Models\ChartOfAccount;
use App\Models\Firm;
use App\Services\AccountingEntitlementPolicyService;
use App\Services\ChartOfAccountsService;
use App\Services\EntitlementService;
use App\Services\ExpenseCategoryService;
use App\Services\TenantSafeAccountingPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            new TenantSafeAccountingPolicyService(),
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
        $this->expectException(\Illuminate\Database\QueryException::class);

        \Illuminate\Support\Facades\DB::table('expense_categories')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid7(),
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
}
