<?php

namespace Tests\Feature\Accounting;

use App\Enums\ChartOfAccountPurpose;
use App\Enums\ChartOfAccountType;
use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Exceptions\AccountingSetupIncompleteException;
use App\Models\AccountingJournalEntry;
use App\Models\ChartOfAccount;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\AccountingBalanceService;
use App\Services\AccountingOpeningBalanceService;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Accounting Integrity Hardening Pass, item 8 — opening balance /
 * cutover strategy. record() is the one real, irreversible write;
 * validate() is a pure dry-run that never persists anything.
 */
class AccountingOpeningBalanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeFirmWithAccounts(): array
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);

        [$cash, $equity] = $this->runWithFirmContext($firm, fn () => [
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Asset)->purpose(ChartOfAccountPurpose::OperatingCash)->create(),
            ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Equity)->purpose(ChartOfAccountPurpose::OpeningBalanceEquity)->create(),
        ]);

        return [$firm, $cash, $equity];
    }

    public function test_validate_never_persists_anything(): void
    {
        [$firm] = $this->makeFirmWithAccounts();

        $result = app(AccountingOpeningBalanceService::class)->validate($firm, [
            ['purpose' => ChartOfAccountPurpose::OperatingCash, 'debit_cents' => 500000],
            ['purpose' => ChartOfAccountPurpose::OpeningBalanceEquity, 'credit_cents' => 500000],
        ]);

        $this->assertTrue($result->valid);
        $this->assertEmpty($result->errors);
        $this->assertSame(500000, $result->totalDebitCents);
        $this->assertSame(500000, $result->totalCreditCents);
        $this->assertFalse($result->alreadyRecorded);

        $count = $this->runWithFirmContext($firm, fn () => AccountingJournalEntry::count());
        $this->assertSame(0, $count, 'validate() must never write anything.');
    }

    public function test_validate_collects_every_error_in_one_pass(): void
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);
        // No accounts configured at all.

        $result = app(AccountingOpeningBalanceService::class)->validate($firm, [
            ['purpose' => ChartOfAccountPurpose::OperatingCash, 'debit_cents' => 500000],
            ['purpose' => ChartOfAccountPurpose::OpeningBalanceEquity, 'credit_cents' => 400000],
        ]);

        $this->assertFalse($result->valid);
        $this->assertCount(3, $result->errors, 'Both missing accounts AND the imbalance must be reported together.');
    }

    public function test_record_posts_a_balanced_opening_entry(): void
    {
        [$firm, $cash, $equity] = $this->makeFirmWithAccounts();
        $recorder = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $entry = app(AccountingOpeningBalanceService::class)->record(
            $firm, now()->subYear(), [
                ['purpose' => ChartOfAccountPurpose::OperatingCash, 'debit_cents' => 500000],
                ['purpose' => ChartOfAccountPurpose::OpeningBalanceEquity, 'credit_cents' => 500000],
            ],
            'Migrated from Clio, 2026-11-01 export',
            $recorder,
        );

        $this->assertNotNull($entry->id);

        $balanceService = app(AccountingBalanceService::class);
        $this->assertSame(500000, $balanceService->accountBalanceCents($firm, $cash));
        $this->assertSame(500000, $balanceService->accountBalanceCents($firm, $equity));
    }

    public function test_record_cannot_be_called_a_second_time(): void
    {
        [$firm] = $this->makeFirmWithAccounts();
        $recorder = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $service = app(AccountingOpeningBalanceService::class);
        $lines = [
            ['purpose' => ChartOfAccountPurpose::OperatingCash, 'debit_cents' => 100000],
            ['purpose' => ChartOfAccountPurpose::OpeningBalanceEquity, 'credit_cents' => 100000],
        ];

        $service->record($firm, now()->subYear(), $lines, 'First cutover', $recorder);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/may only be recorded once/');

        $service->record($firm, now()->subYear(), $lines, 'Second attempt', $recorder);
    }

    public function test_record_is_blocked_atomically_when_a_purpose_is_missing(): void
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);
        $this->runWithFirmContext($firm, fn () => ChartOfAccount::factory()->forFirm($firm)->type(ChartOfAccountType::Asset)->purpose(ChartOfAccountPurpose::OperatingCash)->create());
        // No OpeningBalanceEquity account configured.
        $recorder = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        try {
            app(AccountingOpeningBalanceService::class)->record(
                $firm, now()->subYear(), [
                    ['purpose' => ChartOfAccountPurpose::OperatingCash, 'debit_cents' => 100000],
                    ['purpose' => ChartOfAccountPurpose::OpeningBalanceEquity, 'credit_cents' => 100000],
                ],
                'Attempted cutover',
                $recorder,
            );
            $this->fail('Expected AccountingSetupIncompleteException.');
        } catch (AccountingSetupIncompleteException $e) {
            $this->assertSame(ChartOfAccountPurpose::OpeningBalanceEquity, $e->purpose);
        }

        $count = $this->runWithFirmContext($firm, fn () => AccountingJournalEntry::count());
        $this->assertSame(0, $count, 'Nothing may commit — not even the opening cash line — when any required account is missing.');
    }

    public function test_record_requires_an_approver_role(): void
    {
        [$firm] = $this->makeFirmWithAccounts();
        $nonApprover = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::Attorney]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/FirmOwner or BillingStaff/');

        app(AccountingOpeningBalanceService::class)->record(
            $firm, now()->subYear(), [
                ['purpose' => ChartOfAccountPurpose::OperatingCash, 'debit_cents' => 100000],
                ['purpose' => ChartOfAccountPurpose::OpeningBalanceEquity, 'credit_cents' => 100000],
            ],
            'Attempted cutover',
            $nonApprover,
        );
    }

    public function test_record_requires_a_non_blank_source_description(): void
    {
        [$firm] = $this->makeFirmWithAccounts();
        $recorder = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $this->expectException(\InvalidArgumentException::class);

        app(AccountingOpeningBalanceService::class)->record(
            $firm, now()->subYear(), [
                ['purpose' => ChartOfAccountPurpose::OperatingCash, 'debit_cents' => 100000],
                ['purpose' => ChartOfAccountPurpose::OpeningBalanceEquity, 'credit_cents' => 100000],
            ],
            '   ',
            $recorder,
        );
    }
}
