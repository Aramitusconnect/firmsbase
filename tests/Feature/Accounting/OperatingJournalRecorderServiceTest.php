<?php

namespace Tests\Feature\Accounting;

use App\Enums\ChartOfAccountType;
use App\Enums\ExpenseApprovalStatus;
use App\Enums\FirmUserRole;
use App\Enums\InvoiceStatus;
use App\Enums\ManualPaymentMethod;
use App\Enums\PaymentClassification;
use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Invoice;
use App\Models\Matter;
use App\Services\AccountingBalanceService;
use App\Services\ExpenseApprovalService;
use App\Services\ManualPaymentService;
use App\Services\TrustAccountService;
use App\Services\TrustDepositService;
use App\Services\TrustLedgerService;
use App\Services\TrustTransferRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Trust\Concerns\SetsUpTrustEligibleFirm;
use Tests\TestCase;

/**
 * Phase D — proves real business events (direct invoice payment, a
 * trust-funded transfer, an approved expense) post real double-entry
 * journal entries, that a retry never double-posts (idempotency), and
 * that a firm with no chart of accounts set up is simply skipped
 * (never blocked) rather than erroring.
 */
class OperatingJournalRecorderServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpTrustEligibleFirm;

    private function makeFirmWithAccounts(): array
    {
        $firm = Firm::factory()->create();

        [$cash, $revenue, $expenseAccount] = $this->runWithFirmContext($firm, fn () => [
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Asset)->create(['account_code' => '1000', 'account_name' => 'Operating Cash']),
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Revenue)->create(['account_code' => '4000', 'account_name' => 'Legal Fee Revenue']),
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Expense)->create(['account_code' => '5000', 'account_name' => 'Office Expense']),
        ]);

        return [$firm, $cash, $revenue, $expenseAccount];
    }

    public function test_direct_operating_payment_on_an_invoice_posts_fees_earned(): void
    {
        [$firm, $cash, $revenue] = $this->makeFirmWithAccounts();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create([
            'subtotal_cents' => 50000, 'total_cents' => 50000,
        ]));

        app(ManualPaymentService::class)->submit(
            $firm, $client, 50000, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment,
            (string) Str::uuid(), invoice: $invoice,
        );

        $entry = $this->runWithFirmContext($firm, fn () => \App\Models\AccountingJournalEntry::with('postings')->where('invoice_id', $invoice->id)->first());

        $this->assertNotNull($entry);
        $this->assertSame(50000, $entry->postings->where('chart_of_account_id', $cash->id)->sum('debit_cents'));
        $this->assertSame(50000, $entry->postings->where('chart_of_account_id', $revenue->id)->sum('credit_cents'));
    }

    public function test_a_firm_with_no_chart_of_accounts_is_gracefully_skipped(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create([
            'subtotal_cents' => 10000, 'total_cents' => 10000,
        ]));

        $payment = app(ManualPaymentService::class)->submit(
            $firm, $client, 10000, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment,
            (string) Str::uuid(), invoice: $invoice,
        );

        $this->assertTrue($payment->isAcceptedOperatingPayment());
        $entryCount = $this->runWithFirmContext($firm, fn () => \App\Models\AccountingJournalEntry::count());
        $this->assertSame(0, $entryCount);
    }

    public function test_trust_to_operating_transfer_posts_fees_earned(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        [$cash, $revenue] = $this->runWithFirmContext($firm, fn () => [
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Asset)->create(['account_code' => '1000', 'account_name' => 'Operating Cash']),
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Revenue)->create(['account_code' => '4000', 'account_name' => 'Legal Fee Revenue']),
        ]);
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forClient($client)->create());
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create([
            'matter_id' => $matter->id, 'subtotal_cents' => 20000, 'total_cents' => 20000,
        ]));

        $requester = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $approver = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $deposits = app(TrustDepositService::class);
        $depositRequest = $deposits->requestDeposit($firm, $ledger, $requester, 20000, $matter);
        $approvedDeposit = $deposits->approveDeposit($firm, $depositRequest, $approver);
        $deposits->post($firm, $ledger, $approvedDeposit, $matter);

        $transferService = app(TrustTransferRequestService::class);
        $request = $transferService->requestTransfer($firm, $ledger, $matter, $invoice, $requester, 15000);
        $transferService->approveTransfer($firm, $request, $approver);
        $transferService->apply($firm, $request->fresh(), $approver);

        $entry = $this->runWithFirmContext($firm, fn () => \App\Models\AccountingJournalEntry::with('postings')->where('trust_transfer_request_id', $request->id)->first());

        $this->assertNotNull($entry);
        $this->assertSame(15000, $entry->postings->where('chart_of_account_id', $cash->id)->sum('debit_cents'));
        $this->assertSame(15000, $entry->postings->where('chart_of_account_id', $revenue->id)->sum('credit_cents'));

        // The trust ledger itself never gained an operating-side entry
        // for the remaining unearned balance — only the transferred
        // portion was recognized.
        $balanceService = app(AccountingBalanceService::class);
        $this->assertSame(15000, $balanceService->matterBalanceCents($firm, $revenue, $matter));
    }

    public function test_expense_approval_posts_expense_paid(): void
    {
        [$firm, , , $expenseAccount] = $this->makeFirmWithAccounts();
        app(\App\Services\EntitlementService::class)->setForSource($firm, 'expenses', \App\Enums\EntitlementSource::AdminOverride, true);
        $category = $this->runWithFirmContext($firm, fn () => ExpenseCategory::factory()->forFirm($firm)->create());
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::Attorney]);
        $approver = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $expense = $this->runWithFirmContext($firm, fn () => Expense::factory()->forFirm($firm)->create([
            'expense_category_id' => $category->id,
            'amount_cents' => 7500,
            'status' => \App\Enums\ExpenseStatus::Submitted,
            'created_by_firm_user_id' => $creator->id,
        ]));

        app(ExpenseApprovalService::class)->approve($firm, $expense, $approver);

        $entry = $this->runWithFirmContext($firm, fn () => \App\Models\AccountingJournalEntry::with('postings')->where('expense_id', $expense->id)->first());

        $this->assertNotNull($entry);
        $this->assertSame(7500, $entry->postings->sum('debit_cents'));
        $this->assertSame(7500, $entry->postings->sum('credit_cents'));
    }

    public function test_retrying_the_same_payment_never_double_posts(): void
    {
        [$firm] = $this->makeFirmWithAccounts();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create([
            'subtotal_cents' => 30000, 'total_cents' => 30000,
        ]));

        $key = (string) Str::uuid();
        $service = app(ManualPaymentService::class);

        $first = $service->submit($firm, $client, 30000, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment, $key, invoice: $invoice);
        $second = $service->submit($firm, $client, 30000, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment, $key, invoice: $invoice);

        $this->assertSame($first->id, $second->id);

        $count = $this->runWithFirmContext($firm, fn () => \App\Models\AccountingJournalEntry::where('invoice_id', $invoice->id)->count());
        $this->assertSame(1, $count);
    }
}
