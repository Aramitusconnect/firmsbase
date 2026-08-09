<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountingJournalSourceType;
use App\Enums\AccountingPeriodEventType;
use App\Enums\AccountingPeriodStatus;
use App\Enums\ChartOfAccountPurpose;
use App\Enums\ChartOfAccountType;
use App\Enums\FirmUserRole;
use App\Models\AccountingPeriodEvent;
use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Services\AccountingJournalPostingService;
use App\Services\AccountingPeriodCloseService;
use App\Services\TrustAccountService;
use App\Services\TrustBalanceService;
use App\Services\TrustDepositService;
use App\Services\TrustLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Trust\Concerns\SetsUpTrustEligibleFirm;
use Tests\TestCase;

/**
 * Phase K — month-end close. A closed period blocks new postings dated
 * inside it; reopening is the only way back in. No auto-correction,
 * no silent historical mutation.
 */
class AccountingPeriodCloseServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpTrustEligibleFirm;

    private function makeFirmWithAccounts(): array
    {
        $firm = Firm::factory()->create();

        [$cash, $revenue] = $this->runWithFirmContext($firm, fn () => [
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Asset)->purpose(ChartOfAccountPurpose::OperatingCash)->create(),
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

    /**
     * Accounting Integrity Hardening Pass, item 7: close()/reopen()
     * enforce authorization THEMSELVES now — not merely via the
     * Filament ClosePeriodAction's own visibility check.
     */
    public function test_closing_a_period_requires_an_approver_role(): void
    {
        [$firm] = $this->makeFirmWithAccounts();
        $nonApprover = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::Attorney]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/FirmOwner or BillingStaff/');

        app(AccountingPeriodCloseService::class)->close($firm, now()->startOfMonth(), now()->endOfMonth(), $nonApprover);
    }

    public function test_reopening_a_period_requires_an_approver_role(): void
    {
        [$firm] = $this->makeFirmWithAccounts();
        $closer = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $nonApprover = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::Attorney]);
        $period = app(AccountingPeriodCloseService::class)->close($firm, now()->startOfMonth(), now()->endOfMonth(), $closer);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/FirmOwner or BillingStaff/');

        app(AccountingPeriodCloseService::class)->reopen($firm, $period, $nonApprover, 'Attempted reopen');
    }

    public function test_reopening_a_period_requires_a_non_empty_reason(): void
    {
        [$firm] = $this->makeFirmWithAccounts();
        $closer = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $period = app(AccountingPeriodCloseService::class)->close($firm, now()->startOfMonth(), now()->endOfMonth(), $closer);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/reason is required/');

        app(AccountingPeriodCloseService::class)->reopen($firm, $period, $closer, '   ');
    }

    /**
     * Accounting Integrity Hardening Pass, item 7 — the immutable audit
     * trail: closing then reopening a period must leave BOTH an
     * unbroken row on the mutable AccountingPeriod itself (closed_at/
     * closed_by never cleared by reopen()) AND two separate,
     * append-only AccountingPeriodEvent rows.
     */
    public function test_close_and_reopen_each_write_an_immutable_period_event_and_never_erase_prior_close_metadata(): void
    {
        [$firm] = $this->makeFirmWithAccounts();
        $closer = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $reopener = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);

        $period = app(AccountingPeriodCloseService::class)->close($firm, now()->startOfMonth(), now()->endOfMonth(), $closer);
        $originalClosedAt = $period->closed_at;

        app(AccountingPeriodCloseService::class)->reopen($firm, $period, $reopener, 'Found a misposted entry');

        $this->runWithFirmContext($firm, function () use ($period, $closer, $reopener, $originalClosedAt) {
            $freshPeriod = $period->fresh();
            $this->assertSame(AccountingPeriodStatus::Reopened, $freshPeriod->status);
            $this->assertSame($closer->id, $freshPeriod->closed_by_firm_user_id, 'reopen() must never erase who originally closed the period.');
            $this->assertEquals($originalClosedAt->timestamp, $freshPeriod->closed_at->timestamp, 'reopen() must never erase when the period was originally closed.');
            $this->assertSame($reopener->id, $freshPeriod->reopened_by_firm_user_id);

            $events = AccountingPeriodEvent::query()->where('accounting_period_id', $period->id)->orderBy('id')->get();
            $this->assertCount(2, $events);
            $this->assertSame(AccountingPeriodEventType::Closed, $events[0]->event_type);
            $this->assertSame($closer->id, $events[0]->actor_firm_user_id);
            $this->assertSame(AccountingPeriodEventType::Reopened, $events[1]->event_type);
            $this->assertSame($reopener->id, $events[1]->actor_firm_user_id);
            $this->assertSame('Found a misposted entry', $events[1]->reason);
        });
    }

    /**
     * Accounting Integrity Hardening Pass, item 6 — proves the trust
     * liability snapshot is a genuine as-of-periodEnd figure: a real
     * trust deposit posted the month AFTER the period has already
     * closed must never change that period's own historical snapshot,
     * even though it DOES change the live "as of right now" balance.
     */
    public function test_a_trust_deposit_after_period_end_does_not_alter_the_closed_periods_historical_trust_liability(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $closer = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $depositApprover = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $periodStart = now()->startOfMonth();
        $periodEnd = now()->endOfMonth();

        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forClient($client)->create());

        // A deposit posted BEFORE period_end — this is what the
        // snapshot must capture.
        $deposits = app(TrustDepositService::class);
        $before = $deposits->requestDeposit($firm, $ledger, $closer, 15000, $matter);
        $deposits->post($firm, $ledger, $deposits->approveDeposit($firm, $before, $depositApprover), $matter);

        $period = app(AccountingPeriodCloseService::class)->close($firm, $periodStart, $periodEnd, $closer);
        $this->assertSame(15000, $period->trust_liability_snapshot_json['total_cents']);

        // A SECOND deposit, posted the month AFTER period_end — must
        // never retroactively change the already-closed period's own
        // snapshot.
        $after = $deposits->requestDeposit($firm, $ledger, $closer, 9000, $matter);
        $deposits->post($firm, $ledger, $deposits->approveDeposit($firm, $after, $depositApprover), $matter);

        $this->assertSame(15000, $period->fresh()->trust_liability_snapshot_json['total_cents'], 'The closed period\'s own snapshot must not move.');

        $liveFigure = $this->runWithFirmContext($firm, fn () => app(TrustBalanceService::class)->firmTrustLiabilityAsOf($firm, now()));
        $this->assertSame(24000, $liveFigure, 'The live "as of now" figure DOES reflect both deposits — proving this is a real as-of query, not a frozen constant.');
    }
}
