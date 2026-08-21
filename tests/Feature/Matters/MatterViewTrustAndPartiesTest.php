<?php

declare(strict_types=1);

namespace Tests\Feature\Matters;

use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\MatterResource;
use App\Filament\Firm\Resources\MatterResource\Pages\ViewMatter;
use App\Filament\Firm\Resources\MatterResource\RelationManagers\PartiesRelationManager;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\MatterTrustBalance;
use App\Models\Party;
use App\Models\TrustLedger;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Trust\Concerns\SetsUpTrustEligibleFirm;
use Tests\TestCase;

/**
 * MatterViewTrustAndPartiesTest — Mission 5A, items 5.3/5.5. Proves the
 * new read-only Trust Balance section on ViewMatter renders the
 * correct honest state (never a fabricated zero — mirrors
 * MatterViewBudgetSectionTest's own "No Budget Configured" proof
 * shape), and that PartiesRelationManager correctly scopes to the
 * matter's own firm.
 */
final class MatterViewTrustAndPartiesTest extends TestCase
{
    use RefreshDatabase, SetsUpTrustEligibleFirm;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    // ------------------------------------------------------------
    // Trust Balance section
    // ------------------------------------------------------------

    public function test_trust_section_shows_not_applicable_when_firm_is_not_trust_eligible(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $response = $this->runWithFirmContext($firm, fn () => $this->get(MatterResource::getUrl('view', ['record' => $matter])));

        $response->assertOk();
        $response->assertSee('Not applicable — trust accounting is not enabled for this firm.');
    }

    public function test_trust_section_shows_not_applicable_when_firm_is_eligible_but_matter_has_no_ledger(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $response = $this->runWithFirmContext($firm, fn () => $this->get(MatterResource::getUrl('view', ['record' => $matter])));

        $response->assertOk();
        $response->assertSee('Not applicable — no trust ledger exists for this matter\'s client.');
    }

    public function test_trust_section_shows_the_real_aggregate_balance_when_a_ledger_and_matter_balance_exist(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, function () use ($firm, $matter) {
            $ledger = TrustLedger::factory()->create([
                'firm_id' => $firm->id,
                'client_id' => $matter->client_id,
            ]);

            MatterTrustBalance::factory()->forLedgerAndMatter($ledger, $matter)->create([
                'balance_cents' => 150000,
            ]);
        });

        $response = $this->runWithFirmContext($firm, fn () => $this->get(MatterResource::getUrl('view', ['record' => $matter])));

        $response->assertOk();
        $response->assertSee('Matter Trust Balance');
        $response->assertSee('$1,500.00');
        $response->assertDontSee('Not applicable');
    }

    public function test_trust_section_shows_a_true_zero_balance_honestly_when_a_ledger_exists_with_no_activity(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, function () use ($firm, $matter) {
            $ledger = TrustLedger::factory()->create([
                'firm_id' => $firm->id,
                'client_id' => $matter->client_id,
            ]);

            MatterTrustBalance::factory()->forLedgerAndMatter($ledger, $matter)->create([
                'balance_cents' => 0,
            ]);
        });

        $response = $this->runWithFirmContext($firm, fn () => $this->get(MatterResource::getUrl('view', ['record' => $matter])));

        $response->assertOk();
        $response->assertSee('Matter Trust Balance');
        $response->assertSee('$0.00');
        $response->assertDontSee('Not applicable');
    }

    // ------------------------------------------------------------
    // PartiesRelationManager — firm scoping
    // ------------------------------------------------------------

    public function test_parties_tab_shows_only_this_matters_own_parties(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $matterA = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $matterB = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        [$partyLinkA, $partyLinkB] = $this->runWithFirmContext($firm, function () use ($firm, $matterA, $matterB) {
            $partyA = Party::factory()->forFirm($firm)->create(['name' => 'Party A']);
            $partyB = Party::factory()->forFirm($firm)->create(['name' => 'Party B']);

            $linkA = MatterParty::factory()->forMatter($matterA)->forParty($partyA)->create();
            $linkB = MatterParty::factory()->forMatter($matterB)->forParty($partyB)->create();

            return [$linkA, $linkB];
        });

        $this->runWithFirmContext($firm, function () use ($matterA, $partyLinkA, $partyLinkB) {
            $test = Livewire::test(PartiesRelationManager::class, [
                'ownerRecord' => $matterA,
                'pageClass' => ViewMatter::class,
            ]);
            $test->assertOk();
            $test->assertCanSeeTableRecords([$partyLinkA]);
            $test->assertCanNotSeeTableRecords([$partyLinkB]);
        });
    }

    public function test_parties_tab_never_becomes_visible_to_a_firm_owner_of_a_different_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());

        $canView = $this->runWithFirmContext(
            $firmB,
            fn () => PartiesRelationManager::canViewForRecord($matterB, ViewMatter::class),
        );

        $this->assertFalse($canView, "A FirmOwner acting in Firm A's own session must never be authorized to view Firm B's matter's Parties tab.");
    }

    public function test_parties_tab_is_hidden_for_a_paralegal_with_no_assignment(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Paralegal);
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $this->assertFalse(PartiesRelationManager::canViewForRecord($matter, ViewMatter::class));
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function actingAsRole(Firm $firm, FirmUserRole $role): FirmUser
    {
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role($role)->create()
        );

        $this->actingAs($firmUser->user);

        return $firmUser;
    }
}
