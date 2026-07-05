<?php

namespace Tests\Feature\Trust\Ledgers;

use App\Enums\TrustLedgerStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Services\TrustAccountService;
use App\Services\TrustLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Trust\Concerns\SetsUpTrustEligibleFirm;
use Tests\TestCase;

class TrustLedgerServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpTrustEligibleFirm;

    private TrustLedgerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TrustLedgerService::class);
    }

    public function test_opening_a_ledger_also_creates_a_paired_zero_balance_row(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();

        $ledger = $this->service->open($firm, $account, $client);

        $this->assertSame(TrustLedgerStatus::Active, $ledger->status);
        $this->assertDatabaseHas('trust_balances', [
            'trust_ledger_id' => $ledger->id,
            'balance_cents' => 0,
        ]);
    }

    public function test_ledger_cannot_be_opened_for_a_client_belonging_to_another_firm(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $otherFirm = Firm::factory()->create();
        $client = Client::factory()->forFirm($otherFirm)->create();

        $this->expectException(\RuntimeException::class);
        $this->service->open($firm, $account, $client);
    }

    public function test_ledger_can_be_frozen_and_closed(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = $this->service->open($firm, $account, $client);

        $this->service->freeze($firm, $ledger);
        $this->assertSame(TrustLedgerStatus::Frozen, $ledger->fresh()->status);

        $this->service->close($firm, $ledger->fresh());
        $this->assertSame(TrustLedgerStatus::Closed, $ledger->fresh()->status);
    }
}
