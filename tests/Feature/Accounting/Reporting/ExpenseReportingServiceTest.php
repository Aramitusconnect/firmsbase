<?php

namespace Tests\Feature\Accounting\Reporting;

use App\Enums\EntitlementSource;
use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Firm;
use App\Models\Matter;
use App\Services\AccountingEntitlementPolicyService;
use App\Services\EntitlementService;
use App\Services\ExpenseReportingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Required: expense reports filter by firm, matter, category, date
 * range, and reimbursable status.
 */
class ExpenseReportingServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExpenseReportingService $service;
    private EntitlementService $entitlements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlements = app(EntitlementService::class);
        $this->service = new ExpenseReportingService(new AccountingEntitlementPolicyService($this->entitlements));
    }

    private function enableExpenses(Firm $firm): void
    {
        $this->entitlements->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);
    }

    public function test_filters_by_firm(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $this->enableExpenses($otherFirm);

        Expense::factory()->forFirm($firm)->count(3)->create();
        Expense::factory()->forFirm($otherFirm)->count(2)->create();

        $this->assertCount(3, $this->service->list($firm));
    }

    public function test_filters_by_matter(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $matter = Matter::factory()->forFirm($firm)->create();

        Expense::factory()->forFirm($firm)->create(['matter_id' => $matter->id]);
        Expense::factory()->forFirm($firm)->create(['matter_id' => null]);

        $this->assertCount(1, $this->service->list($firm, matterId: $matter->id));
    }

    public function test_filters_by_category(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $category = ExpenseCategory::factory()->forFirm($firm)->create();

        Expense::factory()->forFirm($firm)->create(['expense_category_id' => $category->id]);
        Expense::factory()->forFirm($firm)->create();

        $this->assertCount(1, $this->service->list($firm, categoryId: $category->id));
    }

    public function test_filters_by_date_range(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);

        Expense::factory()->forFirm($firm)->create(['expense_date' => now()->subDays(60)]);
        $inRange = Expense::factory()->forFirm($firm)->create(['expense_date' => now()->subDays(5)]);

        $results = $this->service->list($firm, from: now()->subDays(10), to: now());

        $this->assertCount(1, $results);
        $this->assertTrue($results->first()->is($inRange));
    }

    public function test_filters_by_reimbursable_status(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);

        Expense::factory()->forFirm($firm)->reimbursable(true)->create();
        Expense::factory()->forFirm($firm)->reimbursable(false)->create();

        $this->assertCount(1, $this->service->list($firm, reimbursable: true));
    }

    public function test_filters_by_expense_status(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);

        Expense::factory()->forFirm($firm)->status(ExpenseStatus::Approved)->create();
        Expense::factory()->forFirm($firm)->status(ExpenseStatus::Draft)->create();

        $this->assertCount(1, $this->service->list($firm, status: ExpenseStatus::Approved));
    }

    /** Required: disabled Expenses blocks reporting. */
    public function test_reporting_blocked_when_module_disabled(): void
    {
        $firm = Firm::factory()->create();

        $this->expectException(\RuntimeException::class);
        $this->service->list($firm);
    }
}
