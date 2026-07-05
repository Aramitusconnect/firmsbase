<?php

namespace Tests\Feature\Trust\Reconciliation;

use App\Enums\FirmUserRole;
use App\Enums\TrustReconciliationStatus;
use App\Models\Client;
use App\Models\FirmUser;
use App\Services\TrustAccountService;
use App\Services\TrustDepositService;
use App\Services\TrustLedgerService;
use App\Services\TrustReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Trust\Concerns\SetsUpTrustEligibleFirm;
use Tests\TestCase;

class TrustReconciliationServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpTrustEligibleFirm;

    private TrustReconciliationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TrustReconciliationService::class);
    }

    public function test_reconciliation_is_balanced_when_asserted_bank_balance_matches_the_system(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $user = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $deposits = app(TrustDepositService::class);
        $approved = $deposits->approveDeposit($firm, $deposits->requestDeposit($firm, $ledger, $user, 10000), $user);
        $deposits->post($firm, $ledger, $approved);

        $reconciliation = $this->service->run($firm, $account, $user, now()->subMonth(), now(), 10000);

        $this->assertSame(TrustReconciliationStatus::Balanced, $reconciliation->status);
        $this->assertSame(0, $reconciliation->discrepancy_cents);
    }

    public function test_reconciliation_records_a_discrepancy_without_auto_correcting_anything(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $user = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $deposits = app(TrustDepositService::class);
        $approved = $deposits->approveDeposit($firm, $deposits->requestDeposit($firm, $ledger, $user, 10000), $user);
        $deposits->post($firm, $ledger, $approved);

        $reconciliation = $this->service->run($firm, $account, $user, now()->subMonth(), now(), 9500);

        $this->assertSame(TrustReconciliationStatus::Discrepancy, $reconciliation->status);
        $this->assertSame(500, $reconciliation->discrepancy_cents);
        // The system's own cached ledger balance must be untouched by
        // running a reconciliation — discrepancies are never
        // auto-corrected.
        $this->assertSame(10000, $ledger->balance->fresh()->balance_cents);
    }

    public function test_reconciliation_fails_fast_if_a_ledgers_own_cache_is_stale(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $user = FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);

        $ledger->balance->update(['balance_cents' => 999]);

        $this->expectException(\RuntimeException::class);
        $this->service->run($firm, $account, $user, now()->subMonth(), now(), 999);
    }
}
