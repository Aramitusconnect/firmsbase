<?php

namespace Tests\Feature\Accounting\Export;

use App\Enums\AccountingExportBatchStatus;
use App\Enums\AccountingExportLineStatus;
use App\Enums\ChartOfAccountType;
use App\Enums\EntitlementSource;
use App\Enums\ExpenseStatus;
use App\Models\ChartOfAccount;
use App\Models\Expense;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\AccountingEntitlementPolicyService;
use App\Services\AccountingExportBatchService;
use App\Services\AccountingExportErrorLogger;
use App\Services\AccountingExportLineBuilderService;
use App\Services\AccountingExportSimulationService;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingExportSimulationServiceTest extends TestCase
{
    use RefreshDatabase;

    private AccountingExportSimulationService $simulation;
    private AccountingExportLineBuilderService $lineBuilder;
    private AccountingExportBatchService $batchService;
    private EntitlementService $entitlements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlements = app(EntitlementService::class);
        $policy = new AccountingEntitlementPolicyService($this->entitlements);
        $this->batchService = new AccountingExportBatchService($policy);
        $this->lineBuilder = new AccountingExportLineBuilderService($policy);
        $this->simulation = new AccountingExportSimulationService($this->batchService, new AccountingExportErrorLogger());
    }

    private function firmWithExpenses(): Firm
    {
        $firm = Firm::factory()->create();
        $this->entitlements->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);

        return $firm;
    }

    /** Required: fake QuickBooks export produces a valid logged batch. */
    public function test_fake_export_creates_a_completed_logged_batch(): void
    {
        $firm = $this->firmWithExpenses();
        $requester = FirmUser::factory()->create(['firm_id' => $firm->id]);
        $expenseAccount = ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Expense)->create();
        $category = \App\Models\ExpenseCategory::factory()->forFirm($firm)->create(['chart_of_accounts_id' => $expenseAccount->id]);
        Expense::factory()->forFirm($firm)->status(ExpenseStatus::Approved)
            ->create(['expense_category_id' => $category->id, 'expense_date' => now()->subDays(1)]);

        $batch = $this->batchService->request($firm, $requester, now()->subDays(10), now());
        $this->lineBuilder->buildForBatch($batch);

        $completed = $this->simulation->run($batch);

        $this->assertSame(AccountingExportBatchStatus::Completed, $completed->status);
        $this->assertSame(1, $completed->lines()->where('status', AccountingExportLineStatus::Exported->value)->count());
    }

    /** Required: export line errors are reported per line. */
    public function test_missing_mapping_line_fails_with_a_logged_error(): void
    {
        $firm = $this->firmWithExpenses();
        $requester = FirmUser::factory()->create(['firm_id' => $firm->id]);
        // No chart-of-accounts mapping exists at all.
        Expense::factory()->forFirm($firm)->status(ExpenseStatus::Approved)->create(['expense_date' => now()->subDays(1)]);

        $batch = $this->batchService->request($firm, $requester, now()->subDays(10), now());
        $this->lineBuilder->buildForBatch($batch);

        $completed = $this->simulation->run($batch);

        $this->assertSame(AccountingExportBatchStatus::CompletedWithErrors, $completed->status);
        $failedLine = $completed->lines()->where('status', AccountingExportLineStatus::Failed->value)->first();
        $this->assertNotNull($failedLine);
        $this->assertSame(1, $failedLine->errors()->count());
    }

    public function test_exported_line_cannot_be_re_exported_or_re_failed(): void
    {
        $firm = $this->firmWithExpenses();
        $requester = FirmUser::factory()->create(['firm_id' => $firm->id]);
        $expenseAccount = ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Expense)->create();
        $category = \App\Models\ExpenseCategory::factory()->forFirm($firm)->create(['chart_of_accounts_id' => $expenseAccount->id]);
        Expense::factory()->forFirm($firm)->status(ExpenseStatus::Approved)
            ->create(['expense_category_id' => $category->id, 'expense_date' => now()->subDays(1)]);

        $batch = $this->batchService->request($firm, $requester, now()->subDays(10), now());
        $this->lineBuilder->buildForBatch($batch);
        $this->simulation->run($batch);

        $exportedLine = $batch->lines()->where('status', AccountingExportLineStatus::Exported->value)->firstOrFail();

        $this->expectException(\LogicException::class);
        $exportedLine->update(['status' => AccountingExportLineStatus::Failed]);
    }
}
