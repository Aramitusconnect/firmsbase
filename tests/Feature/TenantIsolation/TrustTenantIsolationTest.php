<?php

namespace Tests\Feature\TenantIsolation;

use App\Exceptions\TenantIsolationException;
use App\Models\Client;
use App\Models\Firm;
use App\Models\Matter;
use App\Services\TenantSafeTrustPolicyService;
use App\Services\TrustAccountService;
use App\Services\TrustCrossMatterProtectionService;
use App\Services\TrustLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Trust\Concerns\SetsUpTrustEligibleFirm;
use Tests\TestCase;

/**
 * Defense-in-depth cross-firm and cross-matter isolation
 * (TenantSafeTrustPolicyService), independent of BelongsToTenant's
 * global scope. Also covers the explicit "no cross-matter use of trust
 * funds" check (correction #10): a matter belonging to a different
 * client than the ledger's client must always be rejected.
 */
class TrustTenantIsolationTest extends TestCase
{
    use RefreshDatabase, SetsUpTrustEligibleFirm;

    public function test_trust_account_belonging_to_another_firm_is_rejected(): void
    {
        $firmA = $this->makeTrustEligibleFirm();
        $firmB = $this->makeTrustEligibleFirm();
        $accountA = app(TrustAccountService::class)->open($firmA, 'Firm A Trust Account');

        $this->expectException(TenantIsolationException::class);
        app(TenantSafeTrustPolicyService::class)->assertTrustAccountBelongsToFirm($accountA, $firmB);
    }

    public function test_trust_ledger_belonging_to_another_firm_is_rejected(): void
    {
        $firmA = $this->makeTrustEligibleFirm();
        $firmB = $this->makeTrustEligibleFirm();
        $accountA = app(TrustAccountService::class)->open($firmA, 'Firm A Trust Account');
        $clientA = Client::factory()->forFirm($firmA)->create();
        $ledgerA = app(TrustLedgerService::class)->open($firmA, $accountA, $clientA);

        $this->expectException(TenantIsolationException::class);
        app(TenantSafeTrustPolicyService::class)->assertTrustLedgerBelongsToFirm($ledgerA, $firmB);
    }

    public function test_matter_belonging_to_a_different_client_than_the_ledger_is_rejected(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $otherClient = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $matterForOtherClient = Matter::factory()->forClient($otherClient)->create();

        $this->expectException(TenantIsolationException::class);
        app(TrustCrossMatterProtectionService::class)->assertMatterEligibleForLedger($matterForOtherClient, $ledger);
    }

    public function test_matter_belonging_to_a_different_firm_than_the_ledger_is_rejected(): void
    {
        $firmA = $this->makeTrustEligibleFirm();
        $firmB = Firm::factory()->create();
        $account = app(TrustAccountService::class)->open($firmA, 'Firm A Trust Account');
        $client = Client::factory()->forFirm($firmA)->create();
        $ledger = app(TrustLedgerService::class)->open($firmA, $account, $client);
        $matterForOtherFirm = Matter::factory()->forFirm($firmB)->create();

        $this->expectException(TenantIsolationException::class);
        app(TrustCrossMatterProtectionService::class)->assertMatterEligibleForLedger($matterForOtherFirm, $ledger);
    }

    public function test_a_matching_matter_and_ledger_passes_without_exception(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $account = app(TrustAccountService::class)->open($firm, 'Firm IOLTA Trust Account');
        $client = Client::factory()->forFirm($firm)->create();
        $ledger = app(TrustLedgerService::class)->open($firm, $account, $client);
        $matter = Matter::factory()->forClient($client)->create();

        app(TrustCrossMatterProtectionService::class)->assertMatterEligibleForLedger($matter, $ledger);

        $this->assertTrue(true);
    }
}
