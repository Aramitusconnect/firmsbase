<?php

namespace Tests\Feature\Trust\Ledgers;

use App\Enums\TrustLedgerEntryType;
use App\Models\Client;
use App\Services\TrustAccountService;
use App\Services\TrustLedgerEntryReversalService;
use App\Services\TrustLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Trust\Concerns\SetsUpTrustEligibleFirm;
use Tests\TestCase;

class TrustLedgerEntryReversalServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpTrustEligibleFirm;

    private TrustLedgerEntryReversalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TrustLedgerEntryReversalService::class);
    }

    public function test_reversal_creates_a_new_opposite_signed_row_and_leaves_the_original_unchanged(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);

        $original = \App\Models\TrustLedgerEntry::create([
            'firm_id' => $firm->id,
            'trust_ledger_id' => $ledger->id,
            'entry_type' => TrustLedgerEntryType::Deposit,
            'amount_cents' => 10000,
            'posted_at' => now(),
        ]);
        app(\App\Services\TrustBalanceService::class)->recomputeForLedger($ledger);
        $originalAttributesBefore = $original->fresh()->getAttributes();

        $reversal = $this->service->reverse($firm, $ledger, $original->fresh());

        $this->assertSame(TrustLedgerEntryType::Reversal, $reversal->entry_type);
        $this->assertSame(-10000, $reversal->amount_cents);
        $this->assertSame($original->id, $reversal->reverses_entry_id);
        $this->assertSame($originalAttributesBefore, $original->fresh()->getAttributes());
        $this->assertSame(0, $ledger->balance->fresh()->balance_cents);
    }

    public function test_an_entry_cannot_be_reversed_twice(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);

        $original = \App\Models\TrustLedgerEntry::create([
            'firm_id' => $firm->id,
            'trust_ledger_id' => $ledger->id,
            'entry_type' => TrustLedgerEntryType::Deposit,
            'amount_cents' => 10000,
            'posted_at' => now(),
        ]);
        app(\App\Services\TrustBalanceService::class)->recomputeForLedger($ledger);

        $this->service->reverse($firm, $ledger, $original->fresh());

        $this->expectException(\RuntimeException::class);
        $this->service->reverse($firm, $ledger, $original->fresh());
    }

    public function test_a_reversal_entry_cannot_itself_be_reversed(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);

        $original = \App\Models\TrustLedgerEntry::create([
            'firm_id' => $firm->id,
            'trust_ledger_id' => $ledger->id,
            'entry_type' => TrustLedgerEntryType::Deposit,
            'amount_cents' => 10000,
            'posted_at' => now(),
        ]);
        app(\App\Services\TrustBalanceService::class)->recomputeForLedger($ledger);
        $reversal = $this->service->reverse($firm, $ledger, $original->fresh());

        $this->expectException(\RuntimeException::class);
        $this->service->reverse($firm, $ledger, $reversal->fresh());
    }
}
