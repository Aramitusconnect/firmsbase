<?php

namespace Tests\Feature\Accounting\Invoicing;

use App\Enums\EntitlementSource;
use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\Firm;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Services\AccountingEntitlementPolicyService;
use App\Services\EntitlementService;
use App\Services\ReimbursableExpenseInvoiceEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReimbursableExpenseInvoiceEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReimbursableExpenseInvoiceEligibilityService $service;
    private EntitlementService $entitlements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlements = app(EntitlementService::class);
        $this->service = new ReimbursableExpenseInvoiceEligibilityService(new AccountingEntitlementPolicyService($this->entitlements));
    }

    private function enableExpensesAndReimbursement(Firm $firm): void
    {
        $this->entitlements->setForSource(
            $firm, 'expenses', EntitlementSource::AdminOverride, true,
            ['reimbursable_expenses_on_invoices_enabled' => true],
        );
    }

    /** Required: approved reimbursable is eligible only when entitlement and firm setting allow it. */
    public function test_approved_reimbursable_expense_is_eligible(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpensesAndReimbursement($firm);
        $expense = Expense::factory()->forFirm($firm)->reimbursable(true)->status(ExpenseStatus::Approved)->create();

        $decision = $this->service->evaluate($firm, $expense);

        $this->assertTrue($decision->allowed);
    }

    public function test_not_eligible_when_firm_setting_disabled(): void
    {
        $firm = Firm::factory()->create();
        $this->entitlements->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);
        $expense = Expense::factory()->forFirm($firm)->reimbursable(true)->status(ExpenseStatus::Approved)->create();

        $decision = $this->service->evaluate($firm, $expense);

        $this->assertFalse($decision->allowed);
    }

    public function test_not_eligible_when_entitlement_disabled(): void
    {
        $firm = Firm::factory()->create();
        $expense = Expense::factory()->forFirm($firm)->reimbursable(true)->status(ExpenseStatus::Approved)->create();

        $decision = $this->service->evaluate($firm, $expense);

        $this->assertFalse($decision->allowed);
    }

    /** Required: rejected/draft/voided/non-reimbursable expense cannot be added to invoice. */
    public function test_rejected_expense_not_eligible(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpensesAndReimbursement($firm);
        $expense = Expense::factory()->forFirm($firm)->reimbursable(true)->status(ExpenseStatus::Rejected)->create();

        $this->assertFalse($this->service->evaluate($firm, $expense)->allowed);
    }

    public function test_draft_expense_not_eligible(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpensesAndReimbursement($firm);
        $expense = Expense::factory()->forFirm($firm)->reimbursable(true)->status(ExpenseStatus::Draft)->create();

        $this->assertFalse($this->service->evaluate($firm, $expense)->allowed);
    }

    public function test_voided_expense_not_eligible(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpensesAndReimbursement($firm);
        $expense = Expense::factory()->forFirm($firm)->reimbursable(true)->status(ExpenseStatus::Voided)->create();

        $this->assertFalse($this->service->evaluate($firm, $expense)->allowed);
    }

    public function test_non_reimbursable_expense_not_eligible(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpensesAndReimbursement($firm);
        $expense = Expense::factory()->forFirm($firm)->reimbursable(false)->status(ExpenseStatus::Approved)->create();

        $this->assertFalse($this->service->evaluate($firm, $expense)->allowed);
    }

    /** Required: approved reimbursable cannot be added twice to the same invoice. */
    public function test_already_invoiced_expense_not_eligible(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpensesAndReimbursement($firm);
        $expense = Expense::factory()->forFirm($firm)->reimbursable(true)->status(ExpenseStatus::Approved)->create();
        $invoice = Invoice::factory()->forFirm($firm)->create();
        InvoiceLine::factory()->create(['invoice_id' => $invoice->id, 'expense_id' => $expense->id]);

        $this->assertFalse($this->service->evaluate($firm, $expense)->allowed);
    }
}
