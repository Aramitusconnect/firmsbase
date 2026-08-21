<?php

namespace Tests\Feature\Trust\Ledgers;

use App\Enums\FirmUserRole;
use App\Enums\TrustLedgerStatus;
use App\Exceptions\TrustLedgerHasResidualBalanceException;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\TrustAccountService;
use App\Services\TrustDepositService;
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

    /**
     * Trust & Accounting Integrity Hardening, Mission 1.2: a ledger with
     * a positive balance must not be closeable — the prior audit's
     * central Mission 1.2 finding, now enforced.
     */
    public function test_a_ledger_with_a_residual_balance_cannot_be_closed(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = $this->service->open($firm, $account, $client);

        $requester = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $approver = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
        $deposits = app(TrustDepositService::class);
        $approved = $deposits->approveDeposit($firm, $deposits->requestDeposit($firm, $ledger, $requester, 5000), $approver);
        $deposits->post($firm, $ledger, $approved);

        try {
            $this->service->close($firm, $ledger->fresh());
            $this->fail('Expected a TrustLedgerHasResidualBalanceException.');
        } catch (TrustLedgerHasResidualBalanceException $e) {
            $this->assertSame(5000, $e->balanceCents);
        }

        $this->assertSame(TrustLedgerStatus::Active, $ledger->fresh()->status);
    }

    public function test_a_ledger_with_a_zero_balance_can_be_closed_directly_from_active(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = $this->service->open($firm, $account, $client);

        $this->service->close($firm, $ledger);

        $this->assertSame(TrustLedgerStatus::Closed, $ledger->fresh()->status);
    }

    public function test_an_already_closed_ledger_cannot_be_closed_again(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = $this->service->open($firm, $account, $client);
        $this->service->close($firm, $ledger);

        $this->expectException(\RuntimeException::class);
        $this->service->close($firm, $ledger->fresh());
    }

    public function test_a_closed_ledger_cannot_be_frozen(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = $this->service->open($firm, $account, $client);
        $this->service->close($firm, $ledger);

        $this->expectException(\RuntimeException::class);
        $this->service->freeze($firm, $ledger->fresh());
    }

    public function test_an_already_frozen_ledger_cannot_be_frozen_again(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = $this->service->open($firm, $account, $client);
        $this->service->freeze($firm, $ledger);

        $this->expectException(\RuntimeException::class);
        $this->service->freeze($firm, $ledger->fresh());
    }
}
