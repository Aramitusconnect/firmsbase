<?php

namespace Tests\Feature\Accounting\Invoicing;

use App\Enums\EntitlementSource;
use App\Enums\ExpenseStatus;
use App\Enums\InvoiceLineType;
use App\Models\Expense;
use App\Models\Firm;
use App\Models\Invoice;
use App\Models\Matter;
use App\Services\AccountingEntitlementPolicyService;
use App\Services\EntitlementService;
use App\Services\ReimbursableExpenseInvoiceEligibilityService;
use App\Services\ReimbursableExpenseInvoiceLineService;
use App\Services\TenantSafeAccountingPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReimbursableExpenseInvoiceLineServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReimbursableExpenseInvoiceLineService $service;
    private EntitlementService $entitlements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlements = app(EntitlementService::class);
        $this->service = new ReimbursableExpenseInvoiceLineService(
            new ReimbursableExpenseInvoiceEligibilityService(new AccountingEntitlementPolicyService($this->entitlements)),
            new TenantSafeAccountingPolicyService(),
        );
    }

    private function enableExpensesAndReimbursement(Firm $firm): void
    {
        $this->entitlements->setForSource(
            $firm, 'expenses', EntitlementSource::AdminOverride, true,
            ['reimbursable_expenses_on_invoices_enabled' => true],
        );
    }

    /** Required: approved reimbursable can create invoice line only when entitlement and firm setting allow it. */
    public function test_invoice_line_created_for_eligible_expense(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpensesAndReimbursement($firm);
        $expense = Expense::factory()->forFirm($firm)->reimbursable(true)->status(ExpenseStatus::Approved)->create(['amount_cents' => 12345]);
        $invoice = Invoice::factory()->forFirm($firm)->create();

        $line = $this->service->createLine($firm, $invoice, $expense);

        $this->assertSame(InvoiceLineType::ReimbursableExpense, $line->line_type);
        $this->assertSame(12345, $line->amount_cents);
        $this->assertSame($expense->id, $line->expense_id);
    }

    public function test_cannot_create_line_when_firm_setting_disabled(): void
    {
        $firm = Firm::factory()->create();
        $this->entitlements->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);
        $expense = Expense::factory()->forFirm($firm)->reimbursable(true)->status(ExpenseStatus::Approved)->create();
        $invoice = Invoice::factory()->forFirm($firm)->create();

        $this->expectException(\RuntimeException::class);
        $this->service->createLine($firm, $invoice, $expense);
    }

    /** Required: approved reimbursable cannot be added twice to the same invoice. */
    public function test_expense_cannot_be_added_twice(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpensesAndReimbursement($firm);
        $expense = Expense::factory()->forFirm($firm)->reimbursable(true)->status(ExpenseStatus::Approved)->create();
        $invoice = Invoice::factory()->forFirm($firm)->create();

        $this->service->createLine($firm, $invoice, $expense);

        $this->expectException(\RuntimeException::class);
        $this->service->createLine($firm, $invoice, $expense);
    }

    /**
     * The DB-level backstop: even bypassing the service, a second
     * invoice_lines row for the same expense_id is rejected by the
     * unique constraint (2026_07_16_900010 migration).
     */
    public function test_database_unique_constraint_blocks_a_second_line_for_the_same_expense(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpensesAndReimbursement($firm);
        $expense = Expense::factory()->forFirm($firm)->reimbursable(true)->status(ExpenseStatus::Approved)->create();
        $invoiceOne = Invoice::factory()->forFirm($firm)->create();
        $invoiceTwo = Invoice::factory()->forFirm($firm)->create();

        $this->service->createLine($firm, $invoiceOne, $expense);

        $this->expectException(\Illuminate\Database\QueryException::class);
        \App\Models\InvoiceLine::query()->create([
            'invoice_id' => $invoiceTwo->id,
            'expense_id' => $expense->id,
            'line_type' => InvoiceLineType::ReimbursableExpense,
            'description' => 'duplicate attempt',
            'quantity' => 1,
            'rate_cents' => 100,
            'amount_cents' => 100,
            'sort_order' => 0,
        ]);
    }

    /** Required: cross-firm invoice/expense/matter combinations are blocked. */
    public function test_cross_firm_invoice_and_expense_blocked(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $this->enableExpensesAndReimbursement($firm);
        $this->enableExpensesAndReimbursement($otherFirm);

        $expense = Expense::factory()->forFirm($firm)->reimbursable(true)->status(ExpenseStatus::Approved)->create();
        $invoice = Invoice::factory()->forFirm($otherFirm)->create();

        $this->expectException(\RuntimeException::class);
        $this->service->createLine($firm, $invoice, $expense);
    }

    public function test_cross_matter_invoice_and_expense_blocked(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpensesAndReimbursement($firm);
        $matterA = Matter::factory()->forFirm($firm)->create();
        $matterB = Matter::factory()->forFirm($firm)->create();

        $expense = Expense::factory()->forFirm($firm)->reimbursable(true)->status(ExpenseStatus::Approved)
            ->create(['matter_id' => $matterA->id]);
        $invoice = Invoice::factory()->forFirm($firm)->create(['matter_id' => $matterB->id]);

        $this->expectException(\RuntimeException::class);
        $this->service->createLine($firm, $invoice, $expense);
    }

    /** Required: rejected/draft/voided/non-reimbursable expense cannot be added to invoice. */
    public function test_ineligible_expense_throws(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpensesAndReimbursement($firm);
        $expense = Expense::factory()->forFirm($firm)->reimbursable(false)->status(ExpenseStatus::Approved)->create();
        $invoice = Invoice::factory()->forFirm($firm)->create();

        $this->expectException(\RuntimeException::class);
        $this->service->createLine($firm, $invoice, $expense);
    }
}
