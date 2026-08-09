<?php

namespace Tests\Feature\Accounting\Entitlement;

use App\Enums\AccountingExportBatchStatus;
use App\Enums\ChartOfAccountType;
use App\Enums\EntitlementSource;
use App\Enums\ExpenseStatus;
use App\Enums\FirmUserRole;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Invoice;
use App\Models\Matter;
use App\Services\AccountingEntitlementPolicyService;
use App\Services\AccountingExportBatchService;
use App\Services\ChartOfAccountsService;
use App\Services\EntitlementService;
use App\Services\ExpenseApprovalService;
use App\Services\ExpenseCategoryService;
use App\Services\ExpenseReceiptService;
use App\Services\ExpenseReportingService;
use App\Services\ExpenseService;
use App\Services\MatterExpenseService;
use App\Services\OperatingJournalRecorderService;
use App\Services\ReimbursableExpenseInvoiceEligibilityService;
use App\Services\ReimbursableExpenseInvoiceLineService;
use App\Services\TenantSafeAccountingPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Required: disabled Expenses blocks every Phase 12 service — expense
 * creation, receipt upload, approval, matter expense linking,
 * reporting, accounting export, and invoice reimbursement (correction
 * #6). There are no routes/controllers/Filament/Blade/Livewire/jobs in
 * this backend-only phase, so this test asserts the backend service
 * gate itself, directly.
 */
class ExpensesDisabledBlocksAllServicesTest extends TestCase
{
    use RefreshDatabase;

    private EntitlementService $entitlements;

    private AccountingEntitlementPolicyService $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlements = app(EntitlementService::class);
        $this->policy = new AccountingEntitlementPolicyService($this->entitlements);
    }

    public function test_expense_creation_is_blocked(): void
    {
        $firm = Firm::factory()->create();
        $category = ExpenseCategory::factory()->forFirm($firm)->create();
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id]);
        $service = new ExpenseService($this->policy, new TenantSafeAccountingPolicyService);

        $this->expectException(\RuntimeException::class);
        $service->create($firm, $category, $creator, 'Vendor', 1000, now());
    }

    public function test_chart_of_accounts_creation_is_blocked(): void
    {
        $firm = Firm::factory()->create();
        $service = new ChartOfAccountsService($this->policy);

        $this->expectException(\RuntimeException::class);
        $service->create($firm, '6000', 'Office Supplies', ChartOfAccountType::Expense);
    }

    public function test_expense_category_creation_is_blocked(): void
    {
        $firm = Firm::factory()->create();
        $service = new ExpenseCategoryService($this->policy, new TenantSafeAccountingPolicyService);

        $this->expectException(\RuntimeException::class);
        $service->create($firm, 'Travel');
    }

    public function test_receipt_upload_is_blocked(): void
    {
        $firm = Firm::factory()->create();
        $this->entitlements->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);
        $expense = Expense::factory()->forFirm($firm)->create();
        // Now disable again to isolate this call.
        $this->entitlements->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, false);
        $service = new ExpenseReceiptService($this->policy, new TenantSafeAccountingPolicyService);

        $this->expectException(\RuntimeException::class);
        $service->upload($firm, $expense, 'a.pdf', 'application/pdf', 10, 'local', 'a.pdf', hash('sha256', 'a'));
    }

    public function test_approval_is_blocked(): void
    {
        $firm = Firm::factory()->create();
        $this->entitlements->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);
        $expense = Expense::factory()->forFirm($firm)->status(ExpenseStatus::Submitted)->create();
        $this->entitlements->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, false);
        $approver = FirmUser::factory()->role(FirmUserRole::FirmOwner)->create(['firm_id' => $firm->id]);
        $service = new ExpenseApprovalService($this->policy, new TenantSafeAccountingPolicyService, app(OperatingJournalRecorderService::class));

        $this->expectException(\RuntimeException::class);
        $service->approve($firm, $expense, $approver);
    }

    public function test_matter_expense_linking_is_blocked(): void
    {
        $firm = Firm::factory()->create();
        $this->entitlements->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);
        $matter = Matter::factory()->forFirm($firm)->create();
        $expense = Expense::factory()->forFirm($firm)->create();
        $this->entitlements->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, false);
        $service = new MatterExpenseService($this->policy, new TenantSafeAccountingPolicyService);

        $this->expectException(\RuntimeException::class);
        $service->link($firm, $matter, $expense);
    }

    public function test_reporting_is_blocked(): void
    {
        $firm = Firm::factory()->create();
        $service = new ExpenseReportingService($this->policy);

        $this->expectException(\RuntimeException::class);
        $service->list($firm);
    }

    public function test_accounting_export_is_blocked(): void
    {
        $firm = Firm::factory()->create();
        $requester = FirmUser::factory()->create(['firm_id' => $firm->id]);
        $service = new AccountingExportBatchService($this->policy);

        $batch = $service->request($firm, $requester, now()->subDays(10), now());

        $this->assertSame(AccountingExportBatchStatus::Blocked, $batch->status);
    }

    public function test_invoice_reimbursement_eligibility_is_blocked(): void
    {
        $firm = Firm::factory()->create();
        $expense = Expense::factory()->forFirm($firm)->reimbursable(true)->status(ExpenseStatus::Approved)->create();
        $service = new ReimbursableExpenseInvoiceEligibilityService($this->policy);

        $decision = $service->evaluate($firm, $expense);

        $this->assertFalse($decision->allowed);
    }

    public function test_invoice_reimbursement_line_creation_is_blocked(): void
    {
        $firm = Firm::factory()->create();
        $expense = Expense::factory()->forFirm($firm)->reimbursable(true)->status(ExpenseStatus::Approved)->create();
        $invoice = Invoice::factory()->forFirm($firm)->create();
        $service = new ReimbursableExpenseInvoiceLineService(
            new ReimbursableExpenseInvoiceEligibilityService($this->policy),
            new TenantSafeAccountingPolicyService,
        );

        $this->expectException(\RuntimeException::class);
        $service->createLine($firm, $invoice, $expense);
    }
}
