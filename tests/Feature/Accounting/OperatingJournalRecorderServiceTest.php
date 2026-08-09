<?php

namespace Tests\Feature\Accounting;

use App\Enums\ChartOfAccountPurpose;
use App\Enums\ChartOfAccountType;
use App\Enums\EntitlementSource;
use App\Enums\ExpenseStatus;
use App\Enums\FirmUserRole;
use App\Enums\InvoiceStatus;
use App\Enums\ManualPaymentMethod;
use App\Enums\PaymentClassification;
use App\Exceptions\AccountingSetupIncompleteException;
use App\Models\AccountingJournalEntry;
use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\Payment;
use App\Services\AccountingBalanceService;
use App\Services\EntitlementService;
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
 * journal entries, and that a retry never double-posts (idempotency).
 *
 * Accounting Integrity Hardening Pass, item 1 — re-audited: a firm
 * that has never enabled accounting at all is gracefully skipped
 * (accounting genuinely does not apply); a firm that HAS enabled it but
 * has an incomplete Chart of Accounts is now atomically BLOCKED, never
 * silently skipped — see the dedicated blocked-atomically tests below.
 */
class OperatingJournalRecorderServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpTrustEligibleFirm;

    private function enableAccounting(Firm $firm): void
    {
        app(EntitlementService::class)->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);
    }

    private function makeFirmWithAccounts(): array
    {
        $firm = Firm::factory()->create();
        $this->enableAccounting($firm);

        [$cash, $revenue, $expenseAccount] = $this->runWithFirmContext($firm, fn () => [
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Asset)->purpose(ChartOfAccountPurpose::OperatingCash)->create(['account_code' => '1000', 'account_name' => 'Operating Cash']),
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Revenue)->purpose(ChartOfAccountPurpose::LegalFeeRevenue)->create(['account_code' => '4000', 'account_name' => 'Legal Fee Revenue']),
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Expense)->purpose(ChartOfAccountPurpose::GeneralOperatingExpense)->create(['account_code' => '5000', 'account_name' => 'Office Expense']),
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

        $entry = $this->runWithFirmContext($firm, fn () => AccountingJournalEntry::with('postings')->where('invoice_id', $invoice->id)->first());

        $this->assertNotNull($entry);
        $this->assertSame(50000, $entry->postings->where('chart_of_account_id', $cash->id)->sum('debit_cents'));
        $this->assertSame(50000, $entry->postings->where('chart_of_account_id', $revenue->id)->sum('credit_cents'));
    }

    /**
     * Accounting Integrity Hardening Pass, item 1: renamed from "a firm
     * with no chart of accounts is gracefully skipped" — that framing
     * described exactly the silent-null-journal failure mode this
     * hardening pass eliminates. The genuinely graceful "not
     * applicable" case is narrower: a firm that has never enabled
     * accounting at all. See
     * test_a_firm_with_accounting_enabled_but_no_chart_of_accounts_blocks_the_payment_atomically()
     * below for the (now very different) behavior when accounting IS
     * enabled but incompletely configured.
     */
    public function test_a_firm_that_has_never_enabled_accounting_is_gracefully_skipped(): void
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
        $entryCount = $this->runWithFirmContext($firm, fn () => AccountingJournalEntry::count());
        $this->assertSame(0, $entryCount);
    }

    /**
     * Accounting Integrity Hardening Pass, item 1 — the core atomic-
     * failure proof: a firm that HAS enabled accounting but has NOT
     * configured a Chart of Accounts must have the ENTIRE payment
     * blocked, not silently accepted with no accounting consequence.
     * Proves both halves of "no partial state": the right exception
     * type, AND that nothing committed at all — no Payment row exists
     * for this idempotency key.
     */
    public function test_a_firm_with_accounting_enabled_but_no_chart_of_accounts_blocks_the_payment_atomically(): void
    {
        $firm = Firm::factory()->create();
        $this->enableAccounting($firm);
        // Deliberately no chart_of_accounts rows at all.
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create([
            'subtotal_cents' => 10000, 'total_cents' => 10000,
        ]));
        $idempotencyKey = (string) Str::uuid();

        try {
            app(ManualPaymentService::class)->submit(
                $firm, $client, 10000, ManualPaymentMethod::Check, PaymentClassification::OperatingPayment,
                $idempotencyKey, invoice: $invoice,
            );
            $this->fail('Expected AccountingSetupIncompleteException.');
        } catch (AccountingSetupIncompleteException $e) {
            $this->assertSame(ChartOfAccountPurpose::OperatingCash, $e->purpose);
        }

        $this->runWithFirmContext($firm, function () use ($firm, $idempotencyKey) {
            $this->assertNull(Payment::query()->where('firm_id', $firm->id)->where('idempotency_key', $idempotencyKey)->first(), 'No Payment row may exist — the entire transaction must have rolled back.');
            $this->assertSame(0, AccountingJournalEntry::count());
        });
    }

    public function test_trust_to_operating_transfer_posts_fees_earned(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $this->enableAccounting($firm);
        [$cash, $revenue] = $this->runWithFirmContext($firm, fn () => [
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Asset)->purpose(ChartOfAccountPurpose::OperatingCash)->create(['account_code' => '1000', 'account_name' => 'Operating Cash']),
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Revenue)->purpose(ChartOfAccountPurpose::LegalFeeRevenue)->create(['account_code' => '4000', 'account_name' => 'Legal Fee Revenue']),
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

        $entry = $this->runWithFirmContext($firm, fn () => AccountingJournalEntry::with('postings')->where('trust_transfer_request_id', $request->id)->first());

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
        app(EntitlementService::class)->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);
        $category = $this->runWithFirmContext($firm, fn () => ExpenseCategory::factory()->forFirm($firm)->create());
        $creator = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::Attorney]);
        $approver = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $expense = $this->runWithFirmContext($firm, fn () => Expense::factory()->forFirm($firm)->create([
            'expense_category_id' => $category->id,
            'amount_cents' => 7500,
            'status' => ExpenseStatus::Submitted,
            'created_by_firm_user_id' => $creator->id,
        ]));

        app(ExpenseApprovalService::class)->approve($firm, $expense, $approver);

        $entry = $this->runWithFirmContext($firm, fn () => AccountingJournalEntry::with('postings')->where('expense_id', $expense->id)->first());

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

        $count = $this->runWithFirmContext($firm, fn () => AccountingJournalEntry::where('invoice_id', $invoice->id)->count());
        $this->assertSame(1, $count);
    }
}
