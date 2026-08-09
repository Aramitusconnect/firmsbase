<?php

namespace Tests\Feature\Accounting\Expenses;

use App\Enums\EntitlementSource;
use App\Enums\ExpenseStatus;
use App\Enums\FirmUserRole;
use App\Models\Expense;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\AccountingEntitlementPolicyService;
use App\Services\EntitlementService;
use App\Services\ExpenseApprovalService;
use App\Services\TenantSafeAccountingPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExpenseApprovalService $service;
    private EntitlementService $entitlements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlements = app(EntitlementService::class);
        $this->service = new ExpenseApprovalService(
            new AccountingEntitlementPolicyService($this->entitlements),
            new TenantSafeAccountingPolicyService(),
            app(\App\Services\OperatingJournalRecorderService::class),
        );
    }

    private function enableExpenses(Firm $firm): void
    {
        $this->entitlements->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);
    }

    /** Required: expenses can be approved. */
    public function test_expense_can_be_approved(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $expense = Expense::factory()->forFirm($firm)->status(ExpenseStatus::Submitted)->create();
        $approver = FirmUser::factory()->role(FirmUserRole::FirmOwner)->create(['firm_id' => $firm->id]);

        $approval = $this->service->approve($firm, $expense, $approver);

        // expenses and expense_approvals now both have permanent FORCE
        // ROW LEVEL SECURITY (see database/migrations/2026_08_27_950020
        // and 2026_08_27_950022). fresh()/assertDatabaseHas() query with
        // no tenant context of their own, so they would (incorrectly)
        // see zero rows against these now-forced tables unless wrapped —
        // matching this project's established convention (see e.g.
        // MatterExpenseServiceTest).
        $this->runWithFirmContext($firm, function () use ($expense, $approval) {
            $this->assertSame(ExpenseStatus::Approved, $expense->fresh()->status);
            $this->assertDatabaseHas('expense_approvals', ['id' => $approval->id, 'expense_id' => $expense->id]);
        });
    }

    public function test_expense_can_be_rejected(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $expense = Expense::factory()->forFirm($firm)->status(ExpenseStatus::Submitted)->create();
        $approver = FirmUser::factory()->role(FirmUserRole::BillingStaff)->create(['firm_id' => $firm->id]);

        $this->service->reject($firm, $expense, $approver, 'Missing documentation.');

        $this->runWithFirmContext($firm, function () use ($expense) {
            $this->assertSame(ExpenseStatus::Rejected, $expense->fresh()->status);
        });
    }

    /**
     * Correction #5: only FirmOwner/BillingStaff may approve.
     * Attorneys, paralegals, legal assistants, and receptionists may
     * not, by default.
     */
    public function test_only_firm_owner_or_billing_staff_can_approve(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);

        foreach ([FirmUserRole::Attorney, FirmUserRole::Paralegal, FirmUserRole::LegalAssistant, FirmUserRole::Receptionist] as $role) {
            $expense = Expense::factory()->forFirm($firm)->status(ExpenseStatus::Submitted)->create();
            $user = FirmUser::factory()->role($role)->create(['firm_id' => $firm->id]);

            try {
                $this->service->approve($firm, $expense, $user);
                $this->fail("{$role->value} should not be able to approve expenses.");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('FirmOwner or BillingStaff', $e->getMessage());
            }
        }
    }

    public function test_approval_blocked_when_module_disabled(): void
    {
        $firm = Firm::factory()->create();
        $expense = Expense::factory()->forFirm($firm)->status(ExpenseStatus::Submitted)->create();
        $approver = FirmUser::factory()->role(FirmUserRole::FirmOwner)->create(['firm_id' => $firm->id]);

        $this->expectException(\RuntimeException::class);
        $this->service->approve($firm, $expense, $approver);
    }
}
