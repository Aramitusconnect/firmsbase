<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountingJournalSourceType;
use App\Enums\ChartOfAccountType;
use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\Firm;
use App\Services\AccountingBalanceService;
use App\Services\AccountingJournalPostingService;
use App\Services\AccountingJournalReversalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingBalanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private AccountingJournalPostingService $postingService;

    private AccountingBalanceService $balanceService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->postingService = app(AccountingJournalPostingService::class);
        $this->balanceService = app(AccountingBalanceService::class);
    }

    public function test_a_debit_normal_asset_account_balance_is_debits_minus_credits(): void
    {
        $firm = Firm::factory()->create();
        [$cash, $revenue] = $this->runWithFirmContext($firm, fn () => [
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Asset)->create(),
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Revenue)->create(),
        ]);

        $this->postingService->post($firm, AccountingJournalSourceType::InvoicePaymentApplied, 'Deposit', now(), [
            ['chart_of_account_id' => $cash->id, 'debit_cents' => 50000, 'credit_cents' => 0],
            ['chart_of_account_id' => $revenue->id, 'debit_cents' => 0, 'credit_cents' => 50000],
        ]);
        $this->postingService->post($firm, AccountingJournalSourceType::Refund, 'Partial refund', now(), [
            ['chart_of_account_id' => $revenue->id, 'debit_cents' => 10000, 'credit_cents' => 0],
            ['chart_of_account_id' => $cash->id, 'debit_cents' => 0, 'credit_cents' => 10000],
        ]);

        $this->assertSame(40000, $this->balanceService->accountBalanceCents($firm, $cash));
    }

    public function test_a_credit_normal_revenue_account_balance_is_credits_minus_debits(): void
    {
        $firm = Firm::factory()->create();
        [$cash, $revenue] = $this->runWithFirmContext($firm, fn () => [
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Asset)->create(),
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Revenue)->create(),
        ]);

        $this->postingService->post($firm, AccountingJournalSourceType::InvoicePaymentApplied, 'Deposit', now(), [
            ['chart_of_account_id' => $cash->id, 'debit_cents' => 50000, 'credit_cents' => 0],
            ['chart_of_account_id' => $revenue->id, 'debit_cents' => 0, 'credit_cents' => 50000],
        ]);

        $this->assertSame(50000, $this->balanceService->accountBalanceCents($firm, $revenue));
    }

    public function test_client_scoped_balance_only_counts_that_clients_postings(): void
    {
        $firm = Firm::factory()->create();
        [$cash, $revenue, $clientA, $clientB] = $this->runWithFirmContext($firm, fn () => [
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Asset)->create(),
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Revenue)->create(),
            Client::factory()->forFirm($firm)->create(),
            Client::factory()->forFirm($firm)->create(),
        ]);

        $this->postingService->post($firm, AccountingJournalSourceType::InvoicePaymentApplied, 'Client A payment', now(), [
            ['chart_of_account_id' => $cash->id, 'debit_cents' => 20000, 'credit_cents' => 0, 'client_id' => $clientA->id],
            ['chart_of_account_id' => $revenue->id, 'debit_cents' => 0, 'credit_cents' => 20000, 'client_id' => $clientA->id],
        ]);
        $this->postingService->post($firm, AccountingJournalSourceType::InvoicePaymentApplied, 'Client B payment', now(), [
            ['chart_of_account_id' => $cash->id, 'debit_cents' => 5000, 'credit_cents' => 0, 'client_id' => $clientB->id],
            ['chart_of_account_id' => $revenue->id, 'debit_cents' => 0, 'credit_cents' => 5000, 'client_id' => $clientB->id],
        ]);

        $this->assertSame(20000, $this->balanceService->clientBalanceCents($firm, $revenue, $clientA));
        $this->assertSame(5000, $this->balanceService->clientBalanceCents($firm, $revenue, $clientB));
        $this->assertSame(25000, $this->balanceService->accountBalanceCents($firm, $revenue));
    }

    public function test_a_reversed_entry_returns_the_balance_to_zero(): void
    {
        $firm = Firm::factory()->create();
        [$cash, $revenue] = $this->runWithFirmContext($firm, fn () => [
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Asset)->create(),
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Revenue)->create(),
        ]);

        $entry = $this->postingService->post($firm, AccountingJournalSourceType::InvoicePaymentApplied, 'Deposit', now(), [
            ['chart_of_account_id' => $cash->id, 'debit_cents' => 15000, 'credit_cents' => 0],
            ['chart_of_account_id' => $revenue->id, 'debit_cents' => 0, 'credit_cents' => 15000],
        ]);

        app(AccountingJournalReversalService::class)->reverse($firm, $entry, 'Undo');

        $this->assertSame(0, $this->balanceService->accountBalanceCents($firm, $cash));
        $this->assertSame(0, $this->balanceService->accountBalanceCents($firm, $revenue));
    }
}
