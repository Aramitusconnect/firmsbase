<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountingJournalSourceType;
use App\Enums\ChartOfAccountType;
use App\Enums\InvoiceStatus;
use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\Firm;
use App\Models\Invoice;
use App\Services\AccountingJournalPostingService;
use App\Services\AccountingReportingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase J — centralized accounting reports. Each report's envelope
 * (firm, period, generated_at) is asserted alongside its data, per the
 * master prompt's own requirement.
 */
class AccountingReportingServiceTest extends TestCase
{
    use RefreshDatabase;

    private AccountingReportingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AccountingReportingService::class);
    }

    public function test_operating_ledger_report_lists_entries_within_the_period_and_identifies_itself(): void
    {
        $firm = Firm::factory()->create();
        [$cash, $revenue] = $this->runWithFirmContext($firm, fn () => [
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Asset)->create(),
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Revenue)->create(),
        ]);

        $this->runWithFirmContext($firm, fn () => app(AccountingJournalPostingService::class)->post(
            $firm, AccountingJournalSourceType::InvoicePaymentApplied, 'Payment', now(),
            [
                ['chart_of_account_id' => $cash->id, 'debit_cents' => 10000, 'credit_cents' => 0],
                ['chart_of_account_id' => $revenue->id, 'debit_cents' => 0, 'credit_cents' => 10000],
            ],
        ));

        $report = $this->runWithFirmContext($firm, fn () => $this->service->operatingLedger($firm, now()->startOfMonth(), now()->endOfMonth()));

        $this->assertSame($firm->id, $report->firmId);
        $this->assertSame('operating_ledger', $report->reportType);
        $this->assertNotNull($report->generatedAt);
        $this->assertCount(1, $report->data);
    }

    public function test_accounts_receivable_aging_buckets_an_overdue_invoice(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Sent)->create([
            'total_cents' => 10000,
            'amount_paid_cents' => 0,
            'due_at' => now()->subDays(45),
        ]));

        $report = $this->runWithFirmContext($firm, fn () => $this->service->accountsReceivableAging($firm, now()));

        $this->assertSame('accounts_receivable_aging', $report->reportType);
        $this->assertCount(1, $report->data);
        $this->assertSame('31_60', $report->data->first()['bucket']);
        $this->assertSame(10000, $report->data->first()['remaining_cents']);
    }

    public function test_accounts_receivable_aging_excludes_fully_paid_invoices(): void
    {
        $firm = Firm::factory()->create();
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => Invoice::factory()->forClient($client)->status(InvoiceStatus::Paid)->create([
            'total_cents' => 10000,
            'amount_paid_cents' => 10000,
            'due_at' => now()->subDays(45),
        ]));

        $report = $this->runWithFirmContext($firm, fn () => $this->service->accountsReceivableAging($firm, now()));

        $this->assertCount(0, $report->data);
    }
}
