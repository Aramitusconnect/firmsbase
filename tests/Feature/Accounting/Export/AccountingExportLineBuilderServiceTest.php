<?php

namespace Tests\Feature\Accounting\Export;

use App\Enums\AccountingExportBatchStatus;
use App\Enums\AccountingExportLineStatus;
use App\Enums\ChartOfAccountType;
use App\Enums\EntitlementSource;
use App\Enums\ExpenseStatus;
use App\Enums\ManualPaymentMethod;
use App\Enums\PaymentClassification;
use App\Enums\PaymentStatus;
use App\Models\ChartOfAccount;
use App\Models\Expense;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Payment;
use App\Services\AccountingEntitlementPolicyService;
use App\Services\AccountingExportBatchService;
use App\Services\AccountingExportLineBuilderService;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingExportLineBuilderServiceTest extends TestCase
{
    use RefreshDatabase;

    private AccountingExportLineBuilderService $service;
    private AccountingExportBatchService $batchService;
    private EntitlementService $entitlements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlements = app(EntitlementService::class);
        $this->service = new AccountingExportLineBuilderService(new AccountingEntitlementPolicyService($this->entitlements));
        $this->batchService = new AccountingExportBatchService(new AccountingEntitlementPolicyService($this->entitlements));
    }

    private function firmWithExpenses(): Firm
    {
        $firm = Firm::factory()->create();
        $this->entitlements->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);

        return $firm;
    }

    public function test_builds_a_pending_line_for_an_approved_expense(): void
    {
        $firm = $this->firmWithExpenses();
        $requester = FirmUser::factory()->create(['firm_id' => $firm->id]);
        $batch = $this->batchService->request($firm, $requester, now()->subDays(10), now());
        Expense::factory()->forFirm($firm)->status(ExpenseStatus::Approved)->create(['expense_date' => now()->subDays(2)]);

        $lines = $this->service->buildForBatch($batch);

        $this->assertCount(1, $lines);
        $this->assertSame(AccountingExportLineStatus::Pending, $lines->first()->status);
    }

    /**
     * Required + project rule: a payment whose payment_classification
     * is TrustIoltaPayment must NEVER be exported.
     */
    public function test_trust_iolta_payment_is_never_exported(): void
    {
        $firm = $this->firmWithExpenses();
        $requester = FirmUser::factory()->create(['firm_id' => $firm->id]);
        $batch = $this->batchService->request($firm, $requester, now()->subDays(10), now());

        Payment::query()->create([
            'firm_id' => $firm->id,
            'client_id' => \App\Models\Client::factory()->forFirm($firm)->create()->id,
            'amount_cents' => 10000,
            'currency' => 'usd',
            'payment_method' => ManualPaymentMethod::Cash,
            'payment_classification' => PaymentClassification::TrustIoltaPayment,
            'status' => PaymentStatus::Blocked,
        ]);

        $lines = $this->service->buildForBatch($batch);

        $this->assertCount(0, $lines);
    }

    /** Required: a blocked payment must never be exported. */
    public function test_blocked_payment_is_never_exported(): void
    {
        $firm = $this->firmWithExpenses();
        $requester = FirmUser::factory()->create(['firm_id' => $firm->id]);
        $batch = $this->batchService->request($firm, $requester, now()->subDays(10), now());

        Payment::query()->create([
            'firm_id' => $firm->id,
            'client_id' => \App\Models\Client::factory()->forFirm($firm)->create()->id,
            'amount_cents' => 10000,
            'currency' => 'usd',
            'payment_method' => ManualPaymentMethod::Cash,
            'payment_classification' => PaymentClassification::BlockedPayment,
            'status' => PaymentStatus::Blocked,
        ]);

        $lines = $this->service->buildForBatch($batch);

        $this->assertCount(0, $lines);
    }

    public function test_only_operating_and_succeeded_payments_are_exported(): void
    {
        $firm = $this->firmWithExpenses();
        $requester = FirmUser::factory()->create(['firm_id' => $firm->id]);
        $batch = $this->batchService->request($firm, $requester, now()->subDays(10), now());
        $client = \App\Models\Client::factory()->forFirm($firm)->create();

        // Eligible: operating + succeeded.
        Payment::query()->create([
            'firm_id' => $firm->id, 'client_id' => $client->id, 'amount_cents' => 5000,
            'currency' => 'usd', 'payment_method' => ManualPaymentMethod::Cash,
            'payment_classification' => PaymentClassification::OperatingPayment,
            'status' => PaymentStatus::Succeeded,
        ]);

        // Not eligible: operating but not yet succeeded.
        Payment::query()->create([
            'firm_id' => $firm->id, 'client_id' => $client->id, 'amount_cents' => 5000,
            'currency' => 'usd', 'payment_method' => ManualPaymentMethod::Cash,
            'payment_classification' => PaymentClassification::OperatingPayment,
            'status' => PaymentStatus::Initiated,
        ]);

        $lines = $this->service->buildForBatch($batch);

        $this->assertCount(1, $lines);
    }

    public function test_missing_chart_of_account_mapping_still_creates_a_pending_line(): void
    {
        $firm = $this->firmWithExpenses();
        $requester = FirmUser::factory()->create(['firm_id' => $firm->id]);
        $batch = $this->batchService->request($firm, $requester, now()->subDays(10), now());
        // Expense's category has no chart_of_accounts_id mapping.
        Expense::factory()->forFirm($firm)->status(ExpenseStatus::Approved)->create(['expense_date' => now()->subDays(1)]);

        $lines = $this->service->buildForBatch($batch);

        $this->assertCount(1, $lines);
        $this->assertNull($lines->first()->chart_of_accounts_id);
        $this->assertSame(AccountingExportLineStatus::Pending, $lines->first()->status);
    }
}
