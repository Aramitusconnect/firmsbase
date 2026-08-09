<?php

namespace Tests\Feature\Accounting\Expenses;

use App\Enums\ChartOfAccountPurpose;
use App\Enums\ChartOfAccountType;
use App\Enums\EntitlementSource;
use App\Enums\ExpenseStatus;
use App\Enums\FirmUserRole;
use App\Exceptions\AccountingSetupIncompleteException;
use App\Models\ChartOfAccount;
use App\Models\Expense;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\AccountingEntitlementPolicyService;
use App\Services\EntitlementService;
use App\Services\ExpenseApprovalService;
use App\Services\OperatingJournalRecorderService;
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
            new TenantSafeAccountingPolicyService,
            app(OperatingJournalRecorderService::class),
        );
    }

    private function enableExpenses(Firm $firm): void
    {
        $this->entitlements->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);
    }

    /**
     * Accounting Integrity Hardening Pass, item 1: a firm with
     * accounting ENABLED must have a complete Chart of Accounts before
     * a money-changing expense approval can succeed — see
     * test_approval_is_blocked_atomically_when_chart_of_accounts_is_incomplete()
     * below for the deliberate negative case this setup exists to make
     * possible.
     */
    private function configureChartOfAccounts(Firm $firm): void
    {
        $this->runWithFirmContext($firm, fn () => [
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Asset)->purpose(ChartOfAccountPurpose::OperatingCash)->create(),
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Expense)->purpose(ChartOfAccountPurpose::GeneralOperatingExpense)->create(),
        ]);
    }

    /** Required: expenses can be approved. */
    public function test_expense_can_be_approved(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);
        $this->configureChartOfAccounts($firm);
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
        // Deliberately NOT calling configureChartOfAccounts(): a
        // rejection never reaches OperatingJournalRecorderService at
        // all (see ExpenseApprovalService::recordDecision() — the
        // journal call is gated on ExpenseApprovalStatus::Approved
        // only), so an incomplete Chart of Accounts must never block a
        // rejection.
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

    /**
     * Accounting Integrity Hardening Pass, item 1 — the atomic-failure
     * proof for ExpenseApprovalService specifically: a firm that HAS
     * enabled accounting but has NOT configured its Chart of Accounts
     * must have the entire approval blocked, not silently approved
     * with a missing accounting consequence. Proves BOTH halves of "no
     * partial state": the exception is the right type, AND nothing
     * committed — the expense stays Submitted and no ExpenseApproval
     * row exists.
     */
    public function test_approval_is_blocked_atomically_when_chart_of_accounts_is_incomplete(): void
    {
        $firm = Firm::factory()->create();
        $this->enableExpenses($firm);
        // No configureChartOfAccounts() call — accounting is enabled
        // for this firm, but no chart_of_accounts rows exist at all.
        $expense = Expense::factory()->forFirm($firm)->status(ExpenseStatus::Submitted)->create();
        $approver = FirmUser::factory()->role(FirmUserRole::FirmOwner)->create(['firm_id' => $firm->id]);

        try {
            $this->service->approve($firm, $expense, $approver);
            $this->fail('Expected AccountingSetupIncompleteException.');
        } catch (AccountingSetupIncompleteException $e) {
            $this->assertSame(ChartOfAccountPurpose::OperatingCash, $e->purpose);
        }

        $this->runWithFirmContext($firm, function () use ($expense) {
            $this->assertSame(ExpenseStatus::Submitted, $expense->fresh()->status, 'The approval must not have partially committed.');
            $this->assertDatabaseCount('expense_approvals', 0);
        });
    }
}
