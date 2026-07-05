<?php

namespace Tests\Feature\Trust\Balances;

use App\Enums\FirmUserRole;
use App\Models\Client;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Services\TrustAccountService;
use App\Services\TrustBalanceService;
use App\Services\TrustDepositService;
use App\Services\TrustLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Trust\Concerns\SetsUpTrustEligibleFirm;
use Tests\TestCase;

class TrustBalanceServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpTrustEligibleFirm;

    private TrustBalanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TrustBalanceService::class);
    }

    public function test_ledger_balance_is_recomputed_as_the_sum_of_its_entries(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $requester = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $deposits = app(TrustDepositService::class);
        $firstApproved = $deposits->approveDeposit($firm, $deposits->requestDeposit($firm, $ledger, $requester, 10000), $requester);
        $deposits->post($firm, $ledger, $firstApproved);
        $secondApproved = $deposits->approveDeposit($firm, $deposits->requestDeposit($firm, $ledger, $requester, 5000), $requester);
        $deposits->post($firm, $ledger, $secondApproved);

        $this->assertSame(15000, $ledger->balance->fresh()->balance_cents);
    }

    public function test_matter_balance_is_scoped_independently_of_the_ledger_total(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $matter = Matter::factory()->forClient($client)->create();
        $requester = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $deposits = app(TrustDepositService::class);
        $forMatter = $deposits->approveDeposit($firm, $deposits->requestDeposit($firm, $ledger, $requester, 4000, $matter), $requester);
        $deposits->post($firm, $ledger, $forMatter, $matter);
        $notForMatter = $deposits->approveDeposit($firm, $deposits->requestDeposit($firm, $ledger, $requester, 6000), $requester);
        $deposits->post($firm, $ledger, $notForMatter);

        $matterBalance = \App\Models\MatterTrustBalance::query()
            ->where('trust_ledger_id', $ledger->id)
            ->where('matter_id', $matter->id)
            ->first();

        $this->assertSame(4000, $matterBalance->balance_cents);
        $this->assertSame(10000, $ledger->balance->fresh()->balance_cents);
    }

    public function test_reconcile_cache_against_ledger_detects_no_discrepancy_when_cache_is_correct(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);

        $result = $this->service->reconcileCacheAgainstLedger($ledger);

        $this->assertTrue($result->matches);
        $this->assertSame(0, $result->differenceCents);
    }

    public function test_reconcile_cache_against_ledger_detects_a_stale_cache(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);

        // Directly corrupt the cache without going through the
        // append-only entry pipeline, to simulate drift.
        $ledger->balance->update(['balance_cents' => 99999]);

        $result = $this->service->reconcileCacheAgainstLedger($ledger->fresh());

        $this->assertFalse($result->matches);
        $this->assertSame(99999, $result->differenceCents);
    }
}
