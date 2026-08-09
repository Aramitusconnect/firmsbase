<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountingJournalSourceType;
use App\Enums\ChartOfAccountType;
use App\Enums\FirmUserRole;
use App\Models\ChartOfAccount;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Invoice;
use App\Services\AccountingJournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingJournalPostingServiceTest extends TestCase
{
    use RefreshDatabase;

    private AccountingJournalPostingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AccountingJournalPostingService::class);
    }

    private function makeFirmWithAccounts(): array
    {
        $firm = Firm::factory()->create();

        [$cash, $revenue] = $this->runWithFirmContext($firm, fn () => [
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Asset)->create(['account_code' => '1000', 'account_name' => 'Operating Cash']),
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Revenue)->create(['account_code' => '4000', 'account_name' => 'Legal Fee Revenue']),
        ]);

        return [$firm, $cash, $revenue];
    }

    public function test_a_balanced_two_line_entry_posts_successfully(): void
    {
        [$firm, $cash, $revenue] = $this->makeFirmWithAccounts();
        $poster = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $entry = $this->service->post(
            $firm,
            AccountingJournalSourceType::InvoicePaymentApplied,
            'Payment received on invoice #1',
            now(),
            [
                ['chart_of_account_id' => $cash->id, 'debit_cents' => 50000, 'credit_cents' => 0],
                ['chart_of_account_id' => $revenue->id, 'debit_cents' => 0, 'credit_cents' => 50000],
            ],
            postedBy: $poster,
        );

        $this->assertSame(2, $entry->postings->count());
        $this->assertSame(50000, $entry->postings->sum('debit_cents'));
        $this->assertSame(50000, $entry->postings->sum('credit_cents'));
        $this->assertSame($poster->id, $entry->posted_by_firm_user_id);
    }

    public function test_an_unbalanced_entry_is_rejected(): void
    {
        [$firm, $cash, $revenue] = $this->makeFirmWithAccounts();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not balance/');

        $this->service->post(
            $firm,
            AccountingJournalSourceType::Adjustment,
            'Unbalanced attempt',
            now(),
            [
                ['chart_of_account_id' => $cash->id, 'debit_cents' => 50000, 'credit_cents' => 0],
                ['chart_of_account_id' => $revenue->id, 'debit_cents' => 0, 'credit_cents' => 40000],
            ],
        );
    }

    public function test_a_single_line_entry_is_rejected(): void
    {
        [$firm, $cash] = $this->makeFirmWithAccounts();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least two posting lines/');

        $this->service->post(
            $firm,
            AccountingJournalSourceType::Adjustment,
            'Single line attempt',
            now(),
            [
                ['chart_of_account_id' => $cash->id, 'debit_cents' => 50000, 'credit_cents' => 0],
            ],
        );
    }

    public function test_a_line_with_both_debit_and_credit_is_rejected(): void
    {
        [$firm, $cash, $revenue] = $this->makeFirmWithAccounts();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/either a debit or a credit/');

        $this->service->post(
            $firm,
            AccountingJournalSourceType::Adjustment,
            'Both-sided line attempt',
            now(),
            [
                ['chart_of_account_id' => $cash->id, 'debit_cents' => 1000, 'credit_cents' => 1000],
                ['chart_of_account_id' => $revenue->id, 'debit_cents' => 0, 'credit_cents' => 2000],
            ],
        );
    }

    public function test_posting_against_another_firms_account_is_rejected(): void
    {
        [$firmA] = $this->makeFirmWithAccounts();
        [, $foreignCash, $foreignRevenue] = $this->makeFirmWithAccounts();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not belong to this firm/');

        $this->service->post(
            $firmA,
            AccountingJournalSourceType::Adjustment,
            'Cross-firm account attempt',
            now(),
            [
                ['chart_of_account_id' => $foreignCash->id, 'debit_cents' => 1000, 'credit_cents' => 0],
                ['chart_of_account_id' => $foreignRevenue->id, 'debit_cents' => 0, 'credit_cents' => 1000],
            ],
        );
    }

    public function test_posting_against_an_inactive_account_is_rejected(): void
    {
        [$firm, $cash, $revenue] = $this->makeFirmWithAccounts();
        $this->runWithFirmContext($firm, fn () => $revenue->update(['is_active' => false]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not belong to this firm or is not active/');

        $this->service->post(
            $firm,
            AccountingJournalSourceType::Adjustment,
            'Inactive account attempt',
            now(),
            [
                ['chart_of_account_id' => $cash->id, 'debit_cents' => 1000, 'credit_cents' => 0],
                ['chart_of_account_id' => $revenue->id, 'debit_cents' => 0, 'credit_cents' => 1000],
            ],
        );
    }

    public function test_source_refs_are_stored_on_the_entry(): void
    {
        [$firm, $cash, $revenue] = $this->makeFirmWithAccounts();

        $invoice = $this->runWithFirmContext($firm, fn () => Invoice::factory()->create(['firm_id' => $firm->id]));

        $entry = $this->service->post(
            $firm,
            AccountingJournalSourceType::InvoicePaymentApplied,
            'Payment applied',
            now(),
            [
                ['chart_of_account_id' => $cash->id, 'debit_cents' => 1000, 'credit_cents' => 0],
                ['chart_of_account_id' => $revenue->id, 'debit_cents' => 0, 'credit_cents' => 1000],
            ],
            sourceRefs: ['invoice_id' => $invoice->id],
        );

        $this->assertSame($invoice->id, $entry->invoice_id);
    }
}
