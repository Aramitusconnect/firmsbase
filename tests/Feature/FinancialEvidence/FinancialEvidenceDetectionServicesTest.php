<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialEvidence;

use App\Enums\TrustLedgerEntryType;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\FinancialEvidenceDuplicateTransferDetectionService;
use App\Integrations\Services\FinancialEvidenceLargeDepositDetectionService;
use App\Integrations\Services\FinancialEvidenceReconciliationCandidateDetectionService;
use App\Integrations\Services\FinancialEvidenceRecurringObligationDetectionService;
use App\Models\FinancialEvidenceBankAccount;
use App\Models\FinancialEvidenceDuplicateTransferFlag;
use App\Models\FinancialEvidenceLargeDepositFlag;
use App\Models\FinancialEvidenceLargeDepositThreshold;
use App\Models\FinancialEvidenceMatterAuthorization;
use App\Models\FinancialEvidenceReconciliationCandidate;
use App\Models\FinancialEvidenceTransaction;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\TrustAccount;
use App\Models\TrustLedger;
use App\Models\TrustLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FinancialEvidenceDetectionServicesTest — FirmsVault Live
 * Integrations, Checkpoint 4 ("Plaid financial evidence add-on").
 * Covers the four detection services backing the Review Queues panel:
 * Duplicate Transfer, Large Deposit (config-driven threshold, both
 * platform_default and firm_override precedence), Reconciliation
 * Candidate (never auto-posts), and Recurring Obligation.
 */
class FinancialEvidenceDetectionServicesTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------
    // Duplicate Transfer detection
    // ------------------------------------------------------------

    public function test_flags_a_matching_opposite_sign_pair_within_the_configured_window(): void
    {
        [$firm, $matter, $accountA, $accountB] = $this->makeMatterWithTwoAccounts();

        $this->runWithFirmContext($firm, function () use ($firm, $accountA, $accountB) {
            $this->makeTransaction($firm, $accountA, -5000, now()->subHours(2));
            $this->makeTransaction($firm, $accountB, 5000, now());
        });

        $created = $this->runWithFirmContext($firm, fn () => app(FinancialEvidenceDuplicateTransferDetectionService::class)->evaluate($matter));

        $this->assertSame(1, $created);
        $this->assertSame(1, $this->runWithFirmContext($firm, fn () => FinancialEvidenceDuplicateTransferFlag::query()->where('matter_id', $matter->id)->count()));
    }

    public function test_does_not_flag_a_pair_outside_the_configured_window(): void
    {
        [$firm, $matter, $accountA, $accountB] = $this->makeMatterWithTwoAccounts();

        $this->runWithFirmContext($firm, function () use ($firm, $accountA, $accountB) {
            $this->makeTransaction($firm, $accountA, -5000, now()->subDays(30));
            $this->makeTransaction($firm, $accountB, 5000, now());
        });

        $created = $this->runWithFirmContext($firm, fn () => app(FinancialEvidenceDuplicateTransferDetectionService::class)->evaluate($matter));

        $this->assertSame(0, $created);
    }

    public function test_does_not_flag_two_transactions_on_the_same_account(): void
    {
        [$firm, $matter, $accountA] = $this->makeMatterWithTwoAccounts();

        $this->runWithFirmContext($firm, function () use ($firm, $accountA) {
            $this->makeTransaction($firm, $accountA, -5000, now()->subHours(1));
            $this->makeTransaction($firm, $accountA, 5000, now());
        });

        $created = $this->runWithFirmContext($firm, fn () => app(FinancialEvidenceDuplicateTransferDetectionService::class)->evaluate($matter));

        $this->assertSame(0, $created, 'A pair on the SAME account is not a cross-account transfer and must not be flagged.');
    }

    public function test_evaluate_is_idempotent_and_never_flags_the_same_pair_twice(): void
    {
        [$firm, $matter, $accountA, $accountB] = $this->makeMatterWithTwoAccounts();

        $this->runWithFirmContext($firm, function () use ($firm, $accountA, $accountB) {
            $this->makeTransaction($firm, $accountA, -5000, now()->subHours(2));
            $this->makeTransaction($firm, $accountB, 5000, now());
        });

        $service = app(FinancialEvidenceDuplicateTransferDetectionService::class);
        $first = $this->runWithFirmContext($firm, fn () => $service->evaluate($matter));
        $second = $this->runWithFirmContext($firm, fn () => $service->evaluate($matter));

        $this->assertSame(1, $first);
        $this->assertSame(0, $second, 'Re-running evaluate() must never re-flag an already-flagged pair.');
    }

    // ------------------------------------------------------------
    // Large Deposit detection — config-driven threshold precedence
    // ------------------------------------------------------------

    public function test_resolves_the_config_fallback_when_no_platform_default_or_firm_override_row_exists(): void
    {
        $firm = Firm::factory()->create();

        $resolved = app(FinancialEvidenceLargeDepositDetectionService::class)->resolveThresholdCents($firm);

        $this->assertSame((int) config('financial_evidence.large_deposit_default_threshold_cents', 1_000_000), $resolved);
    }

    public function test_a_platform_default_row_takes_precedence_over_the_bare_config_fallback(): void
    {
        $firm = Firm::factory()->create();

        $this->createThreshold('platform_default', null, 2_000_000);

        $resolved = app(FinancialEvidenceLargeDepositDetectionService::class)->resolveThresholdCents($firm);

        $this->assertSame(2_000_000, $resolved);
    }

    public function test_a_firm_override_row_takes_precedence_over_the_platform_default(): void
    {
        $firm = Firm::factory()->create();

        $this->createThreshold('platform_default', null, 2_000_000);
        $this->createThreshold('firm_override', $firm->id, 500_000);

        $resolved = app(FinancialEvidenceLargeDepositDetectionService::class)->resolveThresholdCents($firm);

        $this->assertSame(500_000, $resolved);
    }

    public function test_a_firm_override_belonging_to_a_different_firm_is_never_applied(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->createThreshold('firm_override', $firmB->id, 100);

        $resolved = app(FinancialEvidenceLargeDepositDetectionService::class)->resolveThresholdCents($firmA);

        $this->assertNotSame(100, $resolved);
    }

    public function test_flags_a_transaction_at_or_above_the_resolved_threshold_and_not_below_it(): void
    {
        [$firm, $matter, $account] = $this->makeMatterWithTwoAccounts();

        $this->createThreshold('platform_default', null, 1_000_000);

        $this->runWithFirmContext($firm, function () use ($firm, $account) {
            $this->makeTransaction($firm, $account, 999_999, now());
            $this->makeTransaction($firm, $account, 1_000_000, now());
        });

        $created = $this->runWithFirmContext($firm, fn () => app(FinancialEvidenceLargeDepositDetectionService::class)->evaluate($matter));

        $this->assertSame(1, $created);

        $flag = $this->runWithFirmContext($firm, fn () => FinancialEvidenceLargeDepositFlag::query()->where('matter_id', $matter->id)->first());
        $this->assertSame(1_000_000, $flag->threshold_cents_applied);
    }

    // ------------------------------------------------------------
    // Reconciliation Candidate detection — never auto-posts
    // ------------------------------------------------------------

    public function test_creates_a_candidate_for_an_amount_and_date_proximate_ledger_entry_never_writing_the_ledger(): void
    {
        [$firm, $matter, $account] = $this->makeMatterWithTwoAccounts();

        $trustAccount = $this->runWithFirmContext($firm, fn () => TrustAccount::factory()->create(['firm_id' => $firm->id]));
        $ledger = $this->runWithFirmContext($firm, fn () => TrustLedger::factory()->create(['firm_id' => $firm->id, 'trust_account_id' => $trustAccount->id]));
        $ledgerEntry = $this->runWithFirmContext($firm, fn () => TrustLedgerEntry::query()->create([
            'firm_id' => $firm->id,
            'trust_ledger_id' => $ledger->id,
            'matter_id' => $matter->id,
            'entry_type' => TrustLedgerEntryType::Deposit,
            'amount_cents' => 42_000,
            'posted_at' => now(),
        ]));

        $this->runWithFirmContext($firm, fn () => $this->makeTransaction($firm, $account, 42_000, now()));

        $trustLedgerCountBefore = TrustLedgerEntry::query()->count();

        $created = $this->runWithFirmContext($firm, fn () => app(FinancialEvidenceReconciliationCandidateDetectionService::class)->evaluate($matter));

        $this->assertSame(1, $created);
        $this->assertSame($trustLedgerCountBefore, TrustLedgerEntry::query()->count(), 'Reconciliation-candidate detection must never itself write a trust_ledger_entries row.');

        $candidate = $this->runWithFirmContext($firm, fn () => FinancialEvidenceReconciliationCandidate::query()->where('matter_id', $matter->id)->first());
        $this->assertSame('candidate', $candidate->status);
        $this->assertSame($ledgerEntry->id, $candidate->trust_ledger_entry_id);
    }

    public function test_does_not_create_a_candidate_when_no_ledger_entries_exist_for_the_matter(): void
    {
        [$firm, $matter, $account] = $this->makeMatterWithTwoAccounts();

        $this->runWithFirmContext($firm, fn () => $this->makeTransaction($firm, $account, 42_000, now()));

        $created = $this->runWithFirmContext($firm, fn () => app(FinancialEvidenceReconciliationCandidateDetectionService::class)->evaluate($matter));

        $this->assertSame(0, $created);
    }

    // ------------------------------------------------------------
    // Recurring Obligation detection
    // ------------------------------------------------------------

    public function test_detects_a_recurring_obligation_at_the_minimum_occurrence_threshold(): void
    {
        [$firm, $matter, $account] = $this->makeMatterWithTwoAccounts();

        $this->runWithFirmContext($firm, function () use ($firm, $account) {
            $this->makeTransaction($firm, $account, 15000, now()->subMonths(2), 'Acme Rent Co');
            $this->makeTransaction($firm, $account, 15000, now()->subMonths(1), 'Acme Rent Co');
        });

        $obligations = $this->runWithFirmContext($firm, fn () => app(FinancialEvidenceRecurringObligationDetectionService::class)->detect($matter));

        $this->assertCount(1, $obligations);
        $this->assertSame(2, $obligations->first()['occurrences']);
    }

    public function test_a_single_occurrence_is_not_treated_as_recurring(): void
    {
        [$firm, $matter, $account] = $this->makeMatterWithTwoAccounts();

        $this->runWithFirmContext($firm, fn () => $this->makeTransaction($firm, $account, 15000, now(), 'One Time Vendor'));

        $obligations = $this->runWithFirmContext($firm, fn () => app(FinancialEvidenceRecurringObligationDetectionService::class)->detect($matter));

        $this->assertCount(0, $obligations);
    }

    public function test_amounts_outside_the_tolerance_window_form_separate_clusters(): void
    {
        [$firm, $matter, $account] = $this->makeMatterWithTwoAccounts();

        $this->runWithFirmContext($firm, function () use ($firm, $account) {
            $this->makeTransaction($firm, $account, 10000, now()->subMonths(3), 'Variable Vendor');
            $this->makeTransaction($firm, $account, 10000, now()->subMonths(2), 'Variable Vendor');
            $this->makeTransaction($firm, $account, 99999, now()->subMonths(1), 'Variable Vendor');
        });

        $obligations = $this->runWithFirmContext($firm, fn () => app(FinancialEvidenceRecurringObligationDetectionService::class)->detect($matter));

        $this->assertCount(1, $obligations);
        $this->assertSame(2, $obligations->first()['occurrences'], 'The far-outside-tolerance amount must not join the two-occurrence cluster.');
    }

    // ------------------------------------------------------------
    // Cross-matter scoping — a matter never sees another matter's
    // transactions in ANY detection service.
    // ------------------------------------------------------------

    public function test_duplicate_transfer_detection_never_flags_transactions_outside_the_matters_own_authorized_scope(): void
    {
        [$firm, $matterA, $accountA1, $accountA2] = $this->makeMatterWithTwoAccounts();
        [, $matterB] = $this->makeMatterWithTwoAccounts($firm);

        $this->runWithFirmContext($firm, function () use ($firm, $accountA1, $accountA2) {
            $this->makeTransaction($firm, $accountA1, -5000, now()->subHours(2));
            $this->makeTransaction($firm, $accountA2, 5000, now());
        });

        $createdForB = $this->runWithFirmContext($firm, fn () => app(FinancialEvidenceDuplicateTransferDetectionService::class)->evaluate($matterB));

        $this->assertSame(0, $createdForB, 'Matter B has no authorized bank accounts of its own and must never see Matter A\'s transaction pair.');
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * @return array{0: Firm, 1: Matter, 2: FinancialEvidenceBankAccount, 3: FinancialEvidenceBankAccount}
     */
    private function makeMatterWithTwoAccounts(?Firm $firm = null): array
    {
        $firm = $firm ?? Firm::factory()->create();

        return $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = Matter::factory()->forFirm($firm)->create();
            $connection = FirmIntegration::factory()->forFirm($firm)->create();

            $accountA = FinancialEvidenceBankAccount::query()->create([
                'firm_id' => $firm->id,
                'firm_integration_id' => $connection->id,
                'plaid_account_id' => 'acc_'.Str::random(12),
                'raw_json' => [],
            ]);

            $accountB = FinancialEvidenceBankAccount::query()->create([
                'firm_id' => $firm->id,
                'firm_integration_id' => $connection->id,
                'plaid_account_id' => 'acc_'.Str::random(12),
                'raw_json' => [],
            ]);

            FinancialEvidenceMatterAuthorization::query()->create([
                'firm_id' => $firm->id,
                'matter_id' => $matter->id,
                'firm_integration_id' => $connection->id,
            ]);

            return [$firm, $matter, $accountA, $accountB];
        });
    }

    private function makeTransaction(Firm $firm, FinancialEvidenceBankAccount $account, int $amountCents, Carbon $date, ?string $merchant = null): FinancialEvidenceTransaction
    {
        return FinancialEvidenceTransaction::query()->create([
            'firm_id' => $firm->id,
            'firm_integration_id' => $account->firm_integration_id,
            'plaid_transaction_id' => 'txn_'.Str::random(16),
            'plaid_account_id' => $account->plaid_account_id,
            'bank_account_id' => $account->id,
            'amount_cents' => $amountCents,
            'transaction_date' => $date->toDateString(),
            'merchant_name' => $merchant,
            'pending' => false,
            'raw_json' => [],
        ]);
    }

    /**
     * Uses forceCreate() rather than create(): 'uuid' is a NOT NULL
     * column on financial_evidence_large_deposit_thresholds but is
     * NOT present in the model's own $fillable array (unlike every
     * other new model in this checkpoint, none of which use the
     * HasPublicUuid trait here either, so nothing auto-populates it) —
     * a plain mass-assigned create(['uuid' => ...]) silently drops the
     * uuid key and then fails NOT NULL. Flagged in the test-writer's
     * report as a latent defect: the moment a future implementer
     * builds an admin-facing create action for a firm_override row
     * (the design's own stated future work — no such write path exists
     * anywhere in app/ today, confirmed by grep), a plain
     * ::create()/mass-assignment call will fail the exact same way
     * this test would have. Not reachable in current production code
     * (nothing in app/ writes to this table today), so not blocking,
     * but real.
     */
    private function createThreshold(string $scopeType, ?int $scopeId, int $thresholdCents): FinancialEvidenceLargeDepositThreshold
    {
        return FinancialEvidenceLargeDepositThreshold::query()->forceCreate([
            'uuid' => (string) Str::uuid(),
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'threshold_cents' => $thresholdCents,
        ]);
    }
}
