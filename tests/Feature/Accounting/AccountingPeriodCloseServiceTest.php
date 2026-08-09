<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountingJournalSourceType;
use App\Enums\AccountingPeriodStatus;
use App\Enums\ChartOfAccountType;
use App\Enums\FirmUserRole;
use App\Models\ChartOfAccount;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\AccountingJournalPostingService;
use App\Services\AccountingPeriodCloseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase K — month-end close. A closed period blocks new postings dated
 * inside it; reopening is the only way back in. No auto-correction,
 * no silent historical mutation.
 */
class AccountingPeriodCloseServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeFirmWithAccounts(): array
    {
        $firm = Firm::factory()->create();

        [$cash, $revenue] = $this->runWithFirmContext($firm, fn () => [
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Asset)->create(),
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Revenue)->create(),
        ]);

        return [$firm, $cash, $revenue];
    }

    public function test_closing_a_period_snapshots_balances_and_marks_it_closed(): void
    {
        [$firm, $cash, $revenue] = $this->makeFirmWithAccounts();
        $closer = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $periodStart = now()->startOfMonth();
        $periodEnd = now()->endOfMonth();

        $this->runWithFirmContext($firm, fn () => app(AccountingJournalPostingService::class)->post(
            $firm, AccountingJournalSourceType::InvoicePaymentApplied, 'Payment', $periodStart->copy()->addDays(2),
            [
                ['chart_of_account_id' => $cash->id, 'debit_cents' => 20000, 'credit_cents' => 0],
                ['chart_of_account_id' => $revenue->id, 'debit_cents' => 0, 'credit_cents' => 20000],
            ],
        ));

        $period = app(AccountingPeriodCloseService::class)->close($firm, $periodStart, $periodEnd, $closer);

        $this->assertSame(AccountingPeriodStatus::Closed, $period->status);
        $this->assertSame(0, $period->opening_balance_cents);
        $this->assertSame(20000, $period->closing_balance_cents);
        $this->assertNotNull($period->closed_at);
    }

    public function test_a_period_cannot_be_closed_twice(): void
    {
        [$firm] = $this->makeFirmWithAccounts();
        $closer = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $periodStart = now()->startOfMonth();
        $periodEnd = now()->endOfMonth();

        app(AccountingPeriodCloseService::class)->close($firm, $periodStart, $periodEnd, $closer);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already been closed/');

        app(AccountingPeriodCloseService::class)->close($firm, $periodStart, $periodEnd, $closer);
    }

    public function test_a_closed_period_rejects_new_postings_dated_inside_it(): void
    {
        [$firm, $cash, $revenue] = $this->makeFirmWithAccounts();
        $closer = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $periodStart = now()->startOfMonth();
        $periodEnd = now()->endOfMonth();

        app(AccountingPeriodCloseService::class)->close($firm, $periodStart, $periodEnd, $closer);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/closed accounting period/');

        $this->runWithFirmContext($firm, fn () => app(AccountingJournalPostingService::class)->post(
            $firm, AccountingJournalSourceType::Adjustment, 'Late posting attempt', $periodStart->copy()->addDays(5),
            [
                ['chart_of_account_id' => $cash->id, 'debit_cents' => 1000, 'credit_cents' => 0],
                ['chart_of_account_id' => $revenue->id, 'debit_cents' => 0, 'credit_cents' => 1000],
            ],
        ));
    }

    public function test_reopening_a_period_allows_postings_again(): void
    {
        [$firm, $cash, $revenue] = $this->makeFirmWithAccounts();
        $closer = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $reopener = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $periodStart = now()->startOfMonth();
        $periodEnd = now()->endOfMonth();

        $period = app(AccountingPeriodCloseService::class)->close($firm, $periodStart, $periodEnd, $closer);

        app(AccountingPeriodCloseService::class)->reopen($firm, $period, $reopener, 'Correcting a misposted entry');

        $entry = $this->runWithFirmContext($firm, fn () => app(AccountingJournalPostingService::class)->post(
            $firm, AccountingJournalSourceType::Adjustment, 'Correction after reopen', $periodStart->copy()->addDays(5),
            [
                ['chart_of_account_id' => $cash->id, 'debit_cents' => 1000, 'credit_cents' => 0],
                ['chart_of_account_id' => $revenue->id, 'debit_cents' => 0, 'credit_cents' => 1000],
            ],
        ));

        $this->assertNotNull($entry);
        $freshPeriod = $this->runWithFirmContext($firm, fn () => $period->fresh());
        $this->assertSame(AccountingPeriodStatus::Reopened, $freshPeriod->status);
        $this->assertSame($reopener->id, $freshPeriod->reopened_by_firm_user_id);
    }

    public function test_postings_outside_the_closed_period_are_unaffected(): void
    {
        [$firm, $cash, $revenue] = $this->makeFirmWithAccounts();
        $closer = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $periodStart = now()->startOfMonth();
        $periodEnd = now()->endOfMonth();

        app(AccountingPeriodCloseService::class)->close($firm, $periodStart, $periodEnd, $closer);

        $entry = $this->runWithFirmContext($firm, fn () => app(AccountingJournalPostingService::class)->post(
            $firm, AccountingJournalSourceType::InvoicePaymentApplied, 'Next month payment', $periodEnd->copy()->addDay(),
            [
                ['chart_of_account_id' => $cash->id, 'debit_cents' => 5000, 'credit_cents' => 0],
                ['chart_of_account_id' => $revenue->id, 'debit_cents' => 0, 'credit_cents' => 5000],
            ],
        ));

        $this->assertNotNull($entry);
    }
}
