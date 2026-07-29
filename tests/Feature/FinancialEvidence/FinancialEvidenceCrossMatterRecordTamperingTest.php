<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialEvidence;

use App\Enums\FirmUserRole;
use App\Integrations\Models\FirmIntegration;
use App\Livewire\FinancialEvidence\FinancialEvidenceTransactionSearchPanel;
use App\Livewire\FinancialEvidence\ReviewQueues\DuplicateTransfersQueuePanel;
use App\Livewire\FinancialEvidence\ReviewQueues\LargeDepositsQueuePanel;
use App\Livewire\FinancialEvidence\ReviewQueues\ReconciliationCandidatesQueuePanel;
use App\Models\FinancialEvidenceBankAccount;
use App\Models\FinancialEvidenceDuplicateTransferFlag;
use App\Models\FinancialEvidenceLargeDepositFlag;
use App\Models\FinancialEvidenceMatterAuthorization;
use App\Models\FinancialEvidenceReconciliationCandidate;
use App\Models\FinancialEvidenceTransaction;
use App\Models\FinancialEvidenceTransactionReview;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * FinancialEvidenceCrossMatterRecordTamperingTest — H2 remediation
 * proof.
 *
 * DuplicateTransfersQueuePanel::resolveFlag(),
 * LargeDepositsQueuePanel::resolveFlag(),
 * ReconciliationCandidatesQueuePanel::decide() and
 * FinancialEvidenceTransactionSearchPanel::recordReview() each
 * validated the CURRENT matter via $this->matter() and then loaded the
 * TARGET record by raw client-supplied primary key
 * (`Model::query()->find($id)`) with no matter_id/firm_id constraint —
 * so a user legitimately authorized for Matter A could tamper with the
 * id and mutate Matter B's (or another firm's) record.
 *
 * The acting user throughout is a BillingStaff who holds the
 * financial-tier view permission AND an active MatterAssignment for
 * Matter A only — deliberately NOT an Attorney/FirmOwner, whose blanket
 * firm-wide matter access would mask the cross-matter boundary this
 * file exists to prove.
 */
class FinancialEvidenceCrossMatterRecordTamperingTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------
    // DuplicateTransfersQueuePanel::resolveFlag()
    // ------------------------------------------------------------

    public function test_duplicate_transfer_flag_from_another_matter_in_the_same_firm_cannot_be_resolved(): void
    {
        $world = $this->makeWorld();
        $panel = $this->mountedPanel(DuplicateTransfersQueuePanel::class, $world);
        $victim = $world['matterB']['duplicateFlag'];

        $this->assertTampering(
            fn () => $this->invokePrivate($panel, 'resolveFlag', [$victim->id, true]),
            $world['firm'],
        );

        $this->assertFlagUntouched($world['firm'], $victim);
    }

    public function test_duplicate_transfer_flag_from_another_firm_cannot_be_resolved(): void
    {
        $world = $this->makeWorld();
        $panel = $this->mountedPanel(DuplicateTransfersQueuePanel::class, $world);
        $victim = $world['otherFirmMatter']['duplicateFlag'];

        $this->assertTampering(
            fn () => $this->invokePrivate($panel, 'resolveFlag', [$victim->id, false]),
            $world['firm'],
        );

        $this->assertFlagUntouched($world['otherFirm'], $victim);
    }

    public function test_the_panels_own_matters_duplicate_transfer_flag_still_resolves_normally(): void
    {
        $world = $this->makeWorld();
        $panel = $this->mountedPanel(DuplicateTransfersQueuePanel::class, $world);
        $own = $world['matterA']['duplicateFlag'];

        $this->runWithFirmContext($world['firm'], fn () => $this->invokePrivate($panel, 'resolveFlag', [$own->id, true]));

        $this->runWithFirmContext($world['firm'], function () use ($own): void {
            $this->assertNotNull($own->fresh()->dismissed_at, 'The remediation must not break the legitimate path.');
        });
    }

    // ------------------------------------------------------------
    // LargeDepositsQueuePanel::resolveFlag()
    // ------------------------------------------------------------

    public function test_large_deposit_flag_from_another_matter_in_the_same_firm_cannot_be_resolved(): void
    {
        $world = $this->makeWorld();
        $panel = $this->mountedPanel(LargeDepositsQueuePanel::class, $world);
        $victim = $world['matterB']['largeDepositFlag'];

        $this->assertTampering(
            fn () => $this->invokePrivate($panel, 'resolveFlag', [$victim->id, true]),
            $world['firm'],
        );

        $this->assertFlagUntouched($world['firm'], $victim);
    }

    public function test_large_deposit_flag_from_another_firm_cannot_be_resolved(): void
    {
        $world = $this->makeWorld();
        $panel = $this->mountedPanel(LargeDepositsQueuePanel::class, $world);
        $victim = $world['otherFirmMatter']['largeDepositFlag'];

        $this->assertTampering(
            fn () => $this->invokePrivate($panel, 'resolveFlag', [$victim->id, false]),
            $world['firm'],
        );

        $this->assertFlagUntouched($world['otherFirm'], $victim);
    }

    public function test_the_panels_own_matters_large_deposit_flag_still_resolves_normally(): void
    {
        $world = $this->makeWorld();
        $panel = $this->mountedPanel(LargeDepositsQueuePanel::class, $world);
        $own = $world['matterA']['largeDepositFlag'];

        $this->runWithFirmContext($world['firm'], fn () => $this->invokePrivate($panel, 'resolveFlag', [$own->id, false]));

        $this->runWithFirmContext($world['firm'], function () use ($own): void {
            $this->assertNotNull($own->fresh()->confirmed_at, 'The remediation must not break the legitimate path.');
        });
    }

    // ------------------------------------------------------------
    // ReconciliationCandidatesQueuePanel::decide()
    // ------------------------------------------------------------

    public function test_reconciliation_candidate_from_another_matter_in_the_same_firm_cannot_be_decided(): void
    {
        $world = $this->makeWorld();
        $panel = $this->mountedPanel(ReconciliationCandidatesQueuePanel::class, $world);
        $victim = $world['matterB']['candidate'];

        $this->assertTampering(
            fn () => $this->invokePrivate($panel, 'decide', [$victim->id, 'rejected']),
            $world['firm'],
        );

        $this->runWithFirmContext($world['firm'], function () use ($victim): void {
            $this->assertSame('candidate', $victim->fresh()->status);
            $this->assertNull($victim->fresh()->reviewed_by_firm_user_id);
        });
    }

    public function test_reconciliation_candidate_from_another_firm_cannot_be_decided(): void
    {
        $world = $this->makeWorld();
        $panel = $this->mountedPanel(ReconciliationCandidatesQueuePanel::class, $world);
        $victim = $world['otherFirmMatter']['candidate'];

        $this->assertTampering(
            fn () => $this->invokePrivate($panel, 'decide', [$victim->id, 'rejected']),
            $world['firm'],
        );

        $this->runWithFirmContext($world['otherFirm'], function () use ($victim): void {
            $this->assertSame('candidate', $victim->fresh()->status);
        });
    }

    public function test_the_panels_own_matters_reconciliation_candidate_still_decides_normally(): void
    {
        $world = $this->makeWorld();
        $panel = $this->mountedPanel(ReconciliationCandidatesQueuePanel::class, $world);
        $own = $world['matterA']['candidate'];

        $this->runWithFirmContext($world['firm'], fn () => $this->invokePrivate($panel, 'decide', [$own->id, 'rejected']));

        $this->runWithFirmContext($world['firm'], function () use ($own): void {
            $this->assertSame('rejected', $own->fresh()->status, 'The remediation must not break the legitimate path.');
        });
    }

    // ------------------------------------------------------------
    // FinancialEvidenceTransactionSearchPanel::recordReview()
    // ------------------------------------------------------------

    public function test_transaction_from_another_matter_in_the_same_firm_cannot_be_reviewed(): void
    {
        $world = $this->makeWorld();
        $panel = $this->mountedPanel(FinancialEvidenceTransactionSearchPanel::class, $world);
        $victim = $world['matterB']['transaction'];

        $this->assertTampering(
            fn () => $this->invokePrivate($panel, 'recordReview', [$victim->id, ['flagged' => true, 'classification' => 'tampered']]),
            $world['firm'],
        );

        $this->assertNoReviewExistsFor($world['firm'], $victim->id);
    }

    public function test_transaction_from_another_firm_cannot_be_reviewed(): void
    {
        $world = $this->makeWorld();
        $panel = $this->mountedPanel(FinancialEvidenceTransactionSearchPanel::class, $world);
        $victim = $world['otherFirmMatter']['transaction'];

        $this->assertTampering(
            fn () => $this->invokePrivate($panel, 'recordReview', [$victim->id, ['flagged' => true]]),
            $world['firm'],
        );

        $this->assertNoReviewExistsFor($world['otherFirm'], $victim->id);
    }

    public function test_the_panels_own_matters_transaction_still_records_a_review_normally(): void
    {
        $world = $this->makeWorld();
        $panel = $this->mountedPanel(FinancialEvidenceTransactionSearchPanel::class, $world);
        $own = $world['matterA']['transaction'];

        $this->runWithFirmContext(
            $world['firm'],
            fn () => $this->invokePrivate($panel, 'recordReview', [$own->id, ['flagged' => true, 'flag_reason' => 'Legitimate.']]),
        );

        $this->runWithFirmContext($world['firm'], function () use ($own, $world): void {
            $review = FinancialEvidenceTransactionReview::query()->where('transaction_id', $own->id)->first();

            $this->assertNotNull($review, 'The remediation must not break the legitimate path.');
            $this->assertSame($world['firm']->id, $review->firm_id);
            $this->assertTrue((bool) $review->flagged);
        });
    }

    // ------------------------------------------------------------
    // Regression guard on the defective idiom itself
    // ------------------------------------------------------------

    /**
     * The four remediated mutation methods must never regress to a bare
     * primary-key load. Scans the whole Livewire/FinancialEvidence tree
     * (not only the four named files) for the `::find(`/`::findOrFail(`
     * idiom on a FinancialEvidence* model — the grep the review itself
     * asked for, kept as an executable guard.
     */
    public function test_no_financial_evidence_panel_loads_a_financial_evidence_model_by_bare_primary_key(): void
    {
        $files = [];
        $directory = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Livewire/FinancialEvidence'))
        );

        foreach ($directory as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        $this->assertNotEmpty($files, 'Sanity check: this scan must not silently scan nothing.');

        foreach ($files as $path) {
            $source = file_get_contents($path);
            $this->assertNotFalse($source);

            $this->assertSame(
                0,
                preg_match('/FinancialEvidence\w*::(query\(\)->)?find(OrFail)?\(/', $source),
                basename($path).' loads a FinancialEvidence* model by bare primary key. Every submitted record id must be '
                    .'resolved through a query constrained by firm_id AND matter_id (or the matter\'s authorized '
                    .'bank-account allowlist) — see the H2 remediation.'
            );
        }
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * Firm 1: Matter A (the panel's own matter, the acting user is
     * assigned to it) and Matter B (same firm, NOT assigned).
     * Firm 2: an entirely separate firm/matter.
     *
     * @return array<string, mixed>
     */
    private function makeWorld(): array
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();

        [$matterA, $seedA] = $this->makeMatterWithEvidence($firm);
        [$matterB, $seedB] = $this->makeMatterWithEvidence($firm);
        [$otherMatter, $otherSeed] = $this->makeMatterWithEvidence($otherFirm);

        $user = User::factory()->create();

        $this->runWithFirmContext($firm, fn () => FirmUser::factory()
            ->role(FirmUserRole::BillingStaff)
            ->forUser($user)
            ->create(['firm_id' => $firm->id]));

        $this->runWithFirmContext(
            $firm,
            fn () => MatterAssignment::factory()->forMatter($matterA)->forUser($user)->create(),
        );

        $this->actingAs($user);

        return [
            'firm' => $firm,
            'otherFirm' => $otherFirm,
            'matterAModel' => $matterA,
            'matterA' => $seedA,
            'matterB' => $seedB,
            'otherFirmMatter' => $otherSeed,
            'user' => $user,
        ];
    }

    /**
     * @return array{0: Matter, 1: array<string, mixed>}
     */
    private function makeMatterWithEvidence(Firm $firm): array
    {
        return $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = Matter::factory()->forFirm($firm)->create();
            $connection = FirmIntegration::factory()->forFirm($firm)->create();

            $account = FinancialEvidenceBankAccount::query()->create([
                'firm_id' => $firm->id,
                'firm_integration_id' => $connection->id,
                'plaid_account_id' => 'acc_'.Str::random(12),
                'account_name' => 'Checking',
                'mask' => '1111',
                'raw_json' => [],
            ]);

            FinancialEvidenceMatterAuthorization::query()->create([
                'firm_id' => $firm->id,
                'matter_id' => $matter->id,
                'firm_integration_id' => $connection->id,
            ]);

            $txnA = FinancialEvidenceTransaction::query()->create([
                'firm_id' => $firm->id,
                'firm_integration_id' => $connection->id,
                'plaid_transaction_id' => 'txn_'.Str::random(16),
                'plaid_account_id' => $account->plaid_account_id,
                'bank_account_id' => $account->id,
                'amount_cents' => 500_00,
                'transaction_date' => now()->toDateString(),
                'merchant_name' => 'Merchant',
                'pending' => false,
                'raw_json' => [],
            ]);

            $txnB = FinancialEvidenceTransaction::query()->create([
                'firm_id' => $firm->id,
                'firm_integration_id' => $connection->id,
                'plaid_transaction_id' => 'txn_'.Str::random(16),
                'plaid_account_id' => $account->plaid_account_id,
                'bank_account_id' => $account->id,
                'amount_cents' => -500_00,
                'transaction_date' => now()->toDateString(),
                'merchant_name' => 'Merchant',
                'pending' => false,
                'raw_json' => [],
            ]);

            return [$matter, [
                'matter' => $matter,
                'account' => $account,
                'connection' => $connection,
                'transaction' => $txnA,
                'duplicateFlag' => FinancialEvidenceDuplicateTransferFlag::query()->create([
                    'firm_id' => $firm->id,
                    'matter_id' => $matter->id,
                    'transaction_id_a' => $txnA->id,
                    'transaction_id_b' => $txnB->id,
                    'detected_at' => now(),
                ]),
                'largeDepositFlag' => FinancialEvidenceLargeDepositFlag::query()->create([
                    'firm_id' => $firm->id,
                    'matter_id' => $matter->id,
                    'transaction_id' => $txnA->id,
                    'threshold_cents_applied' => 100_00,
                    'detected_at' => now(),
                ]),
                'candidate' => FinancialEvidenceReconciliationCandidate::query()->create([
                    'firm_id' => $firm->id,
                    'matter_id' => $matter->id,
                    'transaction_id' => $txnA->id,
                    'trust_ledger_entry_id' => null,
                    'match_confidence' => 'high',
                    'status' => 'candidate',
                ]),
            ]];
        });
    }

    private function mountedPanel(string $panelClass, array $world): object
    {
        $panel = new $panelClass;

        $this->runWithFirmContext($world['firm'], fn () => $panel->mount($world['matterAModel']->id));

        return $panel;
    }

    private function assertTampering(callable $callback, Firm $firm): void
    {
        try {
            $this->runWithFirmContext($firm, $callback);
            $this->fail('A tampered record id outside the panel\'s own matter must be refused, not silently applied.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertFlagUntouched(Firm $firm, object $flag): void
    {
        $this->runWithFirmContext($firm, function () use ($flag): void {
            $fresh = $flag->fresh();

            $this->assertNull($fresh->dismissed_at);
            $this->assertNull($fresh->confirmed_at);
            $this->assertNull($fresh->dismissed_by_firm_user_id);
            $this->assertNull($fresh->confirmed_by_firm_user_id);
        });
    }

    private function assertNoReviewExistsFor(Firm $firm, int $transactionId): void
    {
        $this->runWithFirmContext($firm, function () use ($transactionId): void {
            $this->assertFalse(
                FinancialEvidenceTransactionReview::query()->where('transaction_id', $transactionId)->exists(),
                'No review row may be written against a transaction outside the panel\'s own matter.'
            );
        });
    }

    private function invokePrivate(object $target, string $method, array $args = []): mixed
    {
        $reflection = new ReflectionMethod($target, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($target, $args);
    }
}
