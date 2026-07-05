<?php

namespace Tests\Feature\Accounting\Expenses;

use App\Enums\EntitlementSource;
use App\Models\Expense;
use App\Models\Firm;
use App\Models\Matter;
use App\Services\AccountingEntitlementPolicyService;
use App\Services\EntitlementService;
use App\Services\MatterExpenseService;
use App\Services\TenantSafeAccountingPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatterExpenseServiceTest extends TestCase
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

    /** Required: matter expense link is same-firm only. */
    public function test_matter_expense_link_succeeds_same_firm(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $matter = Matter::factory()->forFirm($firm)->create();
        $expense = Expense::factory()->forFirm($firm)->create();

        $link = $this->service->link($firm, $matter, $expense);

        $this->assertDatabaseHas('matter_expenses', [
            'id' => $link->id, 'matter_id' => $matter->id, 'expense_id' => $expense->id, 'firm_id' => $firm->id,
        ]);
    }

    /** Required: cross-firm invoice/expense/matter combinations are blocked. */
    public function test_matter_expense_link_blocked_cross_firm(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $this->enableExpenses($otherFirm);

        $matter = Matter::factory()->forFirm($otherFirm)->create();
        $expense = Expense::factory()->forFirm($firm)->create();

        $this->expectException(\RuntimeException::class);
        $this->service->link($firm, $matter, $expense);
    }

    /** Required: reimbursable snapshot is preserved. */
    public function test_reimbursable_snapshot_is_preserved_after_expense_changes(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $matter = Matter::factory()->forFirm($firm)->create();
        $expense = Expense::factory()->forFirm($firm)->reimbursable(true)->create();

        $link = $this->service->link($firm, $matter, $expense);
        $this->assertTrue($link->reimbursable_snapshot);

        // Later, the expense's own reimbursable flag changes — the
        // snapshot on the already-created link must NOT change.
        $expense->update(['reimbursable' => false]);

        $this->assertTrue($link->fresh()->reimbursable_snapshot);
    }

    public function test_linking_blocked_when_module_disabled(): void
    {
        $firm = Firm::factory()->create();
        $matter = Matter::factory()->forFirm($firm)->create();
        $expense = Expense::factory()->create(['firm_id' => $firm->id]);

        $this->expectException(\RuntimeException::class);
        $this->service->link($firm, $matter, $expense);
    }
}
