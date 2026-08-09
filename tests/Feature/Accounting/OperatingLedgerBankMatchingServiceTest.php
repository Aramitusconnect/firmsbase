<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountingJournalSourceType;
use App\Enums\ChartOfAccountType;
use App\Integrations\Models\FirmIntegration;
use App\Models\AccountingJournalEntry;
use App\Models\ChartOfAccount;
use App\Models\FinancialEvidenceBankAccount;
use App\Models\FinancialEvidenceTransaction;
use App\Models\Firm;
use App\Services\AccountingJournalPostingService;
use App\Services\OperatingLedgerBankMatchingService;
use App\ValueObjects\OperatingBankMatchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase I — bank-feed reconciliation matching against the operating
 * journal. Plaid evidence is read-only input; this service never
 * writes anything (see the service's own docblock for why nothing is
 * persisted). Reuses FinancialEvidenceReconciliationCandidateDetectionService's
 * established amount+date-window convention without touching Trust at
 * all.
 */
class OperatingLedgerBankMatchingServiceTest extends TestCase
{
    use RefreshDatabase;

    private OperatingLedgerBankMatchingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OperatingLedgerBankMatchingService::class);
    }

    private function makeFirmWithAccountsAndBankConnection(): array
    {
        $firm = Firm::factory()->create();

        return $this->runWithFirmContext($firm, function () use ($firm) {
            [$cash, $revenue] = [
                ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Asset)->create(),
                ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Revenue)->create(),
            ];

            $connection = FirmIntegration::factory()->forFirm($firm)->create();
            $bankAccount = FinancialEvidenceBankAccount::query()->create([
                'firm_id' => $firm->id,
                'firm_integration_id' => $connection->id,
                'plaid_account_id' => 'acc_'.Str::random(12),
                'raw_json' => [],
            ]);

            return [$firm, $cash, $revenue, $bankAccount];
        });
    }

    private function makeTransaction(Firm $firm, FinancialEvidenceBankAccount $account, int $amountCents, Carbon $date): FinancialEvidenceTransaction
    {
        return $this->runWithFirmContext($firm, fn () => FinancialEvidenceTransaction::query()->create([
            'firm_id' => $firm->id,
            'firm_integration_id' => $account->firm_integration_id,
            'plaid_transaction_id' => 'txn_'.Str::random(16),
            'plaid_account_id' => $account->plaid_account_id,
            'bank_account_id' => $account->id,
            'amount_cents' => $amountCents,
            'transaction_date' => $date->toDateString(),
            'pending' => false,
            'raw_json' => [],
        ]));
    }

    public function test_a_transaction_with_exactly_one_matching_journal_entry_is_matched(): void
    {
        [$firm, $cash, $revenue, $bankAccount] = $this->makeFirmWithAccountsAndBankConnection();
        $date = now();

        $this->runWithFirmContext($firm, fn () => app(AccountingJournalPostingService::class)->post(
            $firm, AccountingJournalSourceType::InvoicePaymentApplied, 'Payment', $date,
            [
                ['chart_of_account_id' => $cash->id, 'debit_cents' => 30000, 'credit_cents' => 0],
                ['chart_of_account_id' => $revenue->id, 'debit_cents' => 0, 'credit_cents' => 30000],
            ],
        ));

        $transaction = $this->makeTransaction($firm, $bankAccount, 30000, $date);

        $result = $this->runWithFirmContext($firm, fn () => $this->service->matchOne($firm, $transaction));

        $this->assertSame(OperatingBankMatchResult::STATUS_MATCHED, $result->status);
        $this->assertCount(1, $result->candidateEntries);
    }

    public function test_a_transaction_with_no_matching_entries_is_unmatched(): void
    {
        [$firm, , , $bankAccount] = $this->makeFirmWithAccountsAndBankConnection();

        $transaction = $this->makeTransaction($firm, $bankAccount, 99999, now());

        $result = $this->runWithFirmContext($firm, fn () => $this->service->matchOne($firm, $transaction));

        $this->assertSame(OperatingBankMatchResult::STATUS_UNMATCHED, $result->status);
        $this->assertCount(0, $result->candidateEntries);
    }

    public function test_a_transaction_with_two_equally_plausible_entries_is_ambiguous(): void
    {
        [$firm, $cash, $revenue, $bankAccount] = $this->makeFirmWithAccountsAndBankConnection();
        $date = now();

        $this->runWithFirmContext($firm, function () use ($firm, $cash, $revenue, $date) {
            $poster = app(AccountingJournalPostingService::class);
            $poster->post($firm, AccountingJournalSourceType::InvoicePaymentApplied, 'Payment A', $date, [
                ['chart_of_account_id' => $cash->id, 'debit_cents' => 15000, 'credit_cents' => 0],
                ['chart_of_account_id' => $revenue->id, 'debit_cents' => 0, 'credit_cents' => 15000],
            ]);
            $poster->post($firm, AccountingJournalSourceType::InvoicePaymentApplied, 'Payment B', $date, [
                ['chart_of_account_id' => $cash->id, 'debit_cents' => 15000, 'credit_cents' => 0],
                ['chart_of_account_id' => $revenue->id, 'debit_cents' => 0, 'credit_cents' => 15000],
            ]);
        });

        $transaction = $this->makeTransaction($firm, $bankAccount, 15000, $date);

        $result = $this->runWithFirmContext($firm, fn () => $this->service->matchOne($firm, $transaction));

        $this->assertSame(OperatingBankMatchResult::STATUS_AMBIGUOUS, $result->status);
        $this->assertCount(2, $result->candidateEntries);
    }

    public function test_multiple_smaller_entries_summing_to_the_transaction_amount_are_partially_matched(): void
    {
        [$firm, $cash, $revenue, $bankAccount] = $this->makeFirmWithAccountsAndBankConnection();
        $date = now();

        $this->runWithFirmContext($firm, function () use ($firm, $cash, $revenue, $date) {
            $poster = app(AccountingJournalPostingService::class);
            $poster->post($firm, AccountingJournalSourceType::InvoicePaymentApplied, 'Split part 1', $date, [
                ['chart_of_account_id' => $cash->id, 'debit_cents' => 4000, 'credit_cents' => 0],
                ['chart_of_account_id' => $revenue->id, 'debit_cents' => 0, 'credit_cents' => 4000],
            ]);
            $poster->post($firm, AccountingJournalSourceType::InvoicePaymentApplied, 'Split part 2', $date, [
                ['chart_of_account_id' => $cash->id, 'debit_cents' => 6000, 'credit_cents' => 0],
                ['chart_of_account_id' => $revenue->id, 'debit_cents' => 0, 'credit_cents' => 6000],
            ]);
        });

        $transaction = $this->makeTransaction($firm, $bankAccount, 10000, $date);

        $result = $this->runWithFirmContext($firm, fn () => $this->service->matchOne($firm, $transaction));

        $this->assertSame(OperatingBankMatchResult::STATUS_PARTIALLY_MATCHED, $result->status);
    }

    public function test_matching_never_writes_anything(): void
    {
        [$firm, , , $bankAccount] = $this->makeFirmWithAccountsAndBankConnection();
        $transaction = $this->makeTransaction($firm, $bankAccount, 5000, now());

        $this->runWithFirmContext($firm, fn () => $this->service->matchOne($firm, $transaction));

        $entryCount = $this->runWithFirmContext($firm, fn () => AccountingJournalEntry::count());
        $this->assertSame(0, $entryCount);
    }
}
