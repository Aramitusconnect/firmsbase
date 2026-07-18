<?php

namespace Tests\Feature\Accounting\Expenses;

use App\Enums\EntitlementSource;
use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\AccountingEntitlementPolicyService;
use App\Services\EntitlementService;
use App\Services\ExpenseService;
use App\Services\TenantSafeAccountingPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExpenseService $service;
    private EntitlementService $entitlements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlements = app(EntitlementService::class);
        $this->service = new ExpenseService(
            new AccountingEntitlementPolicyService($this->entitlements),
            new TenantSafeAccountingPolicyService(),
        );
    }

    private function enableExpenses(Firm $firm): void
    {
        $this->entitlements->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);
    }

    /** Required: expenses can be created. */
    public function test_expense_can_be_created(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $category = ExpenseCategory::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id]);

        $expense = $this->service->create(
            $firm, $category, $creator, 'Acme Office Supplies', 5000, now(),
        );

        $this->assertSame(ExpenseStatus::Draft, $expense->status);

        // expenses now has permanent FORCE ROW LEVEL SECURITY (see
        // database/migrations/2026_08_27_950020_prepare_row_level_
        // security_and_force_rls_on_expenses_table.php). assertDatabaseHas()
        // queries with no tenant context of its own, so it would
        // (incorrectly) see zero rows against this now-forced table
        // unless wrapped — matching this project's established
        // convention (see e.g. MatterExpenseServiceTest).
        $this->runWithFirmContext($firm, function () use ($expense, $firm) {
            $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'firm_id' => $firm->id]);
        });
    }

    public function test_expense_creation_blocked_when_module_disabled(): void
    {
        $firm = Firm::factory()->create();
        $category = ExpenseCategory::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id]);

        $this->expectException(\RuntimeException::class);
        $this->service->create($firm, $category, $creator, 'Acme', 1000, now());
    }

    public function test_expense_can_be_submitted(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $expense = Expense::factory()->forFirm($firm)->status(ExpenseStatus::Draft)->create();

        $submitted = $this->service->submit($firm, $expense);

        $this->assertSame(ExpenseStatus::Submitted, $submitted->status);
    }

    public function test_only_draft_expense_can_be_edited(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $expense = Expense::factory()->forFirm($firm)->status(ExpenseStatus::Submitted)->create();

        $this->expectException(\RuntimeException::class);
        $this->service->editWhileDraft($firm, $expense, ['vendor_name' => 'Changed']);
    }

    public function test_expense_can_be_voided(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $expense = Expense::factory()->forFirm($firm)->status(ExpenseStatus::Draft)->create();

        $voided = $this->service->void($firm, $expense);

        $this->assertSame(ExpenseStatus::Voided, $voided->status);
    }
}
