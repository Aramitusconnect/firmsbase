<?php

namespace Tests\Feature\Trust\Chargebacks;

use App\Enums\FirmUserRole;
use App\Enums\TrustChargebackStatus;
use App\Enums\TrustLedgerEntryType;
use App\Models\Client;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Services\TrustAccountService;
use App\Services\TrustChargebackService;
use App\Services\TrustDepositService;
use App\Services\TrustLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Trust\Concerns\SetsUpTrustEligibleFirm;
use Tests\TestCase;

class TrustChargebackServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpTrustEligibleFirm;

    private TrustChargebackService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TrustChargebackService::class);
    }

    private function setupDeposit(int $amount = 10000): array
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $user = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $deposits = app(TrustDepositService::class);
        $approved = $deposits->approveDeposit($firm, $deposits->requestDeposit($firm, $ledger, $user, $amount), $user);
        $entry = $deposits->post($firm, $ledger, $approved);

        return [$firm, $ledger, $entry, $user];
    }

    public function test_full_chargeback_lifecycle_reverses_the_original_deposit_without_mutating_it(): void
    {
        [$firm, $ledger, $originalEntry, $user] = $this->setupDeposit(10000);
        $originalAttributesBefore = $originalEntry->fresh()->getAttributes();

        $chargeback = $this->service->report($firm, $originalEntry, $user, 10000, 'Client disputed with card issuer.');
        $chargeback = $this->service->reverse($firm, $chargeback, $user);
        $chargeback = $this->service->resolve($firm, $chargeback, $user);

        $this->assertSame(TrustChargebackStatus::Resolved, $chargeback->status);
        $this->assertSame($originalAttributesBefore, $originalEntry->fresh()->getAttributes());
        $this->assertDatabaseHas('trust_ledger_entries', [
            'id' => $chargeback->reversal_trust_ledger_entry_id,
            'entry_type' => TrustLedgerEntryType::ChargebackReversal->value,
            'amount_cents' => -10000,
        ]);
        $this->assertSame(0, $ledger->balance->fresh()->balance_cents);
    }

    public function test_chargeback_cannot_be_reported_against_a_non_deposit_entry(): void
    {
        [$firm, $ledger, $originalEntry, $user] = $this->setupDeposit(10000);
        $withdrawal = \App\Models\TrustLedgerEntry::create([
            'firm_id' => $firm->id,
            'trust_ledger_id' => $ledger->id,
            'entry_type' => TrustLedgerEntryType::WithdrawalToInvoice,
            'amount_cents' => -1000,
            'posted_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->service->report($firm, $withdrawal, $user, 1000, 'Not a deposit.');
    }

    public function test_chargeback_cannot_be_resolved_before_it_is_reversed(): void
    {
        [$firm, $ledger, $originalEntry, $user] = $this->setupDeposit(10000);

        $chargeback = $this->service->report($firm, $originalEntry, $user, 10000, 'Disputed.');

        $this->expectException(\RuntimeException::class);
        $this->service->resolve($firm, $chargeback, $user);
    }

    /**
     * Regression test for the Section 39A-3L Checkpoint 4 Phase D bug,
     * exercised through the EXACT real call path it was found in:
     * TrustChargebackService::reverse() -> TrustLedgerEntryReversalService::reverse().
     * No manual tenant-context manipulation is needed here at all —
     * this test's own setupDeposit()/report() calls chain through
     * several eligibility->assertEligible() checks (each of which
     * routes through EntitlementService::resolve()'s
     * runWithFirmContext(), which explicitly clears any ambient
     * database session context in its finally block) before
     * $this->service->reverse() is ever called, which is exactly the
     * real-world condition that made the original entry's ->matter
     * read silently resolve to null instead of throwing. This proves
     * the reversal entry posted through the chargeback flow correctly
     * carries the original entry's matter_id forward — not null.
     */
    public function test_chargeback_reversal_entry_matter_id_matches_the_original_deposits_matter(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $matter = Matter::factory()->forClient($client)->create();
        $user = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $deposits = app(TrustDepositService::class);
        $approved = $deposits->approveDeposit($firm, $deposits->requestDeposit($firm, $ledger, $user, 10000, $matter), $user);
        $originalEntry = $deposits->post($firm, $ledger, $approved, $matter);

        $chargeback = $this->service->report($firm, $originalEntry, $user, 10000, 'Client disputed with card issuer.');
        $chargeback = $this->service->reverse($firm, $chargeback, $user);

        $reversalEntry = \App\Models\TrustLedgerEntry::query()->findOrFail($chargeback->reversal_trust_ledger_entry_id);

        $this->assertSame($matter->id, $reversalEntry->matter_id);
        $this->assertSame(TrustLedgerEntryType::ChargebackReversal, $reversalEntry->entry_type);
        $this->assertSame(-10000, $reversalEntry->amount_cents);

        $matterBalance = $this->runWithFirmContext($firm, fn () => \App\Models\MatterTrustBalance::query()
            ->where('trust_ledger_id', $ledger->id)
            ->where('matter_id', $matter->id)
            ->firstOrFail());
        $this->assertSame(0, $matterBalance->balance_cents);
    }
}
