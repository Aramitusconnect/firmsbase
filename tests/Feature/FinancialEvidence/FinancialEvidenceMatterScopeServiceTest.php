<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialEvidence;

use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\FinancialEvidenceMatterScopeService;
use App\Models\FinancialEvidenceBankAccount;
use App\Models\FinancialEvidenceMatterAuthorization;
use App\Models\Firm;
use App\Models\Matter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FinancialEvidenceMatterScopeServiceTest — FirmsVault Live
 * Integrations, Checkpoint 4 ("Plaid financial evidence add-on"). This
 * is the ONE shared helper every Workspace panel/detection service
 * uses to resolve "which firm_integrations/bank accounts are currently
 * authorized for this matter" — a bug here would silently
 * under/over-scope every panel that consumes it. Proves matter
 * ownership/date-range-authorization scoping and that access expiration
 * (superseded_at) genuinely revokes visibility, not merely display.
 */
class FinancialEvidenceMatterScopeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_empty_when_the_matter_has_no_authorization_at_all(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $ids = $this->runWithFirmContext($firm, fn () => app(FinancialEvidenceMatterScopeService::class)->connectedFirmIntegrationIds($matter));

        $this->assertSame([], $ids);
    }

    public function test_returns_the_authorized_firm_integration_when_an_active_authorization_exists(): void
    {
        [$firm, $matter, $connection] = $this->makeAuthorizedMatter();

        $ids = $this->runWithFirmContext($firm, fn () => app(FinancialEvidenceMatterScopeService::class)->connectedFirmIntegrationIds($matter));

        $this->assertSame([$connection->id], $ids);
    }

    public function test_a_superseded_authorization_no_longer_counts_as_connected_access_expiration_genuinely_revokes(): void
    {
        [$firm, $matter, $connection, $authorization] = $this->makeAuthorizedMatter();

        $this->runWithFirmContext($firm, fn () => $authorization->update(['superseded_at' => now()]));

        $ids = $this->runWithFirmContext($firm, fn () => app(FinancialEvidenceMatterScopeService::class)->connectedFirmIntegrationIds($matter));

        $this->assertSame([], $ids, 'A revoked/superseded authorization must no longer scope any bank account into the matter\'s visible evidence.');
    }

    public function test_connected_bank_account_ids_only_includes_accounts_under_an_authorized_connection(): void
    {
        [$firm, $matter, $connection] = $this->makeAuthorizedMatter();

        $account = $this->runWithFirmContext($firm, fn () => FinancialEvidenceBankAccount::query()->create([
            'firm_id' => $firm->id,
            'firm_integration_id' => $connection->id,
            'plaid_account_id' => 'acc_'.Str::random(12),
            'raw_json' => [],
        ]));

        // An account under an UNAUTHORIZED connection for this matter.
        $otherConnection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $unauthorizedAccount = $this->runWithFirmContext($firm, fn () => FinancialEvidenceBankAccount::query()->create([
            'firm_id' => $firm->id,
            'firm_integration_id' => $otherConnection->id,
            'plaid_account_id' => 'acc_'.Str::random(12),
            'raw_json' => [],
        ]));

        $ids = $this->runWithFirmContext($firm, fn () => app(FinancialEvidenceMatterScopeService::class)->connectedBankAccountIds($matter));

        $this->assertSame([$account->id], $ids);
        $this->assertNotContains($unauthorizedAccount->id, $ids);
    }

    public function test_a_renewal_supersedes_the_prior_authorization_and_the_new_row_becomes_the_scope(): void
    {
        [$firm, $matter, $connectionOld, $authorizationOld] = $this->makeAuthorizedMatter();

        $connectionNew = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, function () use ($firm, $matter, $authorizationOld, $connectionNew) {
            $authorizationOld->update(['superseded_at' => now()]);

            FinancialEvidenceMatterAuthorization::query()->create([
                'firm_id' => $firm->id,
                'matter_id' => $matter->id,
                'firm_integration_id' => $connectionNew->id,
            ]);
        });

        $ids = $this->runWithFirmContext($firm, fn () => app(FinancialEvidenceMatterScopeService::class)->connectedFirmIntegrationIds($matter));

        $this->assertSame([$connectionNew->id], $ids);
        $this->assertNotContains($connectionOld->id, $ids);
    }

    public function test_a_second_matters_authorization_never_leaks_into_the_first_matters_scope(): void
    {
        [$firm, $matterA, $connectionA] = $this->makeAuthorizedMatter();
        $matterB = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $connectionB = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, fn () => FinancialEvidenceMatterAuthorization::query()->create([
            'firm_id' => $firm->id,
            'matter_id' => $matterB->id,
            'firm_integration_id' => $connectionB->id,
        ]));

        $idsForA = $this->runWithFirmContext($firm, fn () => app(FinancialEvidenceMatterScopeService::class)->connectedFirmIntegrationIds($matterA));

        $this->assertSame([$connectionA->id], $idsForA);
        $this->assertNotContains($connectionB->id, $idsForA);
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * @return array{0: Firm, 1: Matter, 2: FirmIntegration, 3: FinancialEvidenceMatterAuthorization}
     */
    private function makeAuthorizedMatter(): array
    {
        $firm = Firm::factory()->create();

        return $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = Matter::factory()->forFirm($firm)->create();
            $connection = FirmIntegration::factory()->forFirm($firm)->create();

            $authorization = FinancialEvidenceMatterAuthorization::query()->create([
                'firm_id' => $firm->id,
                'matter_id' => $matter->id,
                'firm_integration_id' => $connection->id,
            ]);

            return [$firm, $matter, $connection, $authorization];
        });
    }
}
