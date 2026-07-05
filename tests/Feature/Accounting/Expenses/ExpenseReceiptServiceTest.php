<?php

namespace Tests\Feature\Accounting\Expenses;

use App\Enums\EntitlementSource;
use App\Models\Expense;
use App\Models\Firm;
use App\Services\AccountingEntitlementPolicyService;
use App\Services\EntitlementService;
use App\Services\ExpenseReceiptService;
use App\Services\TenantSafeAccountingPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseReceiptServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExpenseReceiptService $service;
    private EntitlementService $entitlements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlements = app(EntitlementService::class);
        $this->service = new ExpenseReceiptService(
            new AccountingEntitlementPolicyService($this->entitlements),
            new TenantSafeAccountingPolicyService(),
        );
    }

    private function enableExpenses(Firm $firm): void
    {
        $this->entitlements->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);
    }

    /** Required: receipts are private/secure. */
    public function test_receipt_upload_succeeds_and_is_private(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $expense = Expense::factory()->forFirm($firm)->create();

        $receipt = $this->service->upload(
            $firm, $expense, 'receipt.pdf', 'application/pdf', 2048,
            'local', 'expense-receipts/abc.pdf', hash('sha256', 'abc'),
        );

        $this->assertDatabaseHas('expense_receipts', ['id' => $receipt->id, 'expense_id' => $expense->id]);

        $otherFirm = Firm::factory()->create();
        $this->assertTrue($this->service->canAccess($receipt, $firm));
        $this->assertFalse($this->service->canAccess($receipt, $otherFirm));
    }

    public function test_expense_cannot_have_two_receipts(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $expense = Expense::factory()->forFirm($firm)->create();

        $this->service->upload($firm, $expense, 'a.pdf', 'application/pdf', 100, 'local', 'a.pdf', hash('sha256', 'a'));

        $this->expectException(\RuntimeException::class);
        $this->service->upload($firm, $expense, 'b.pdf', 'application/pdf', 100, 'local', 'b.pdf', hash('sha256', 'b'));
    }

    public function test_receipt_upload_blocked_when_module_disabled(): void
    {
        $firm = Firm::factory()->create();
        $expense = Expense::factory()->forFirm($firm)->create();

        $this->expectException(\RuntimeException::class);
        $this->service->upload($firm, $expense, 'a.pdf', 'application/pdf', 100, 'local', 'a.pdf', hash('sha256', 'a'));
    }
}
