<?php

declare(strict_types=1);

namespace Tests\Feature\Trust\Filament;

use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\TrustAccountResource;
use App\Filament\Firm\Resources\TrustLedgerEntryResource;
use App\Filament\Firm\Resources\TrustLedgerResource;
use App\Filament\Firm\Resources\TrustLedgerResource\Pages\ListTrustLedgers;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Trust\Concerns\SetsUpTrustEligibleFirm;
use Tests\TestCase;

/**
 * TrustNavigationEligibilityTest — proves rule #4: the entire "Trust
 * Accounting" nav group (all three Resources) is completely invisible
 * for a firm where TrustEligibilityService::isEligible() is false, and
 * visible — with correctly role-gated actions — once eligible.
 */
final class TrustNavigationEligibilityTest extends TestCase
{
    use RefreshDatabase, SetsUpTrustEligibleFirm;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    public function test_trust_resources_are_entirely_hidden_for_an_ineligible_firm(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->assertFalse(TrustAccountResource::canAccess());
        $this->assertFalse(TrustAccountResource::shouldRegisterNavigation());
        $this->assertFalse(TrustLedgerResource::canAccess());
        $this->assertFalse(TrustLedgerResource::shouldRegisterNavigation());
        $this->assertFalse(TrustLedgerEntryResource::canAccess());
        $this->assertFalse(TrustLedgerEntryResource::shouldRegisterNavigation());

        $response = $this->runWithFirmContext($firm, fn () => $this->get(TrustAccountResource::getUrl('index')));
        $response->assertForbidden();
    }

    public function test_trust_resources_are_visible_for_an_eligible_firm_and_an_authorized_role(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->assertTrue(TrustAccountResource::canAccess());
        $this->assertTrue(TrustAccountResource::shouldRegisterNavigation());
        $this->assertTrue(TrustLedgerResource::canAccess());
        $this->assertTrue(TrustLedgerResource::shouldRegisterNavigation());
        $this->assertTrue(TrustLedgerEntryResource::canAccess());
        $this->assertTrue(TrustLedgerEntryResource::shouldRegisterNavigation());

        $response = $this->runWithFirmContext($firm, fn () => $this->get(TrustAccountResource::getUrl('index')));
        $response->assertSuccessful();
    }

    public function test_trust_resources_stay_hidden_for_an_eligible_firm_but_an_unauthorized_role(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $this->actingAsRole($firm, FirmUserRole::Receptionist);

        $this->assertFalse(TrustAccountResource::canAccess());
        $this->assertFalse(TrustLedgerResource::canAccess());
        $this->assertFalse(TrustLedgerEntryResource::canAccess());
    }

    public function test_list_ledgers_page_renders_for_an_eligible_firm(): void
    {
        $firm = $this->makeTrustEligibleFirm();
        $this->actingAsRole($firm, FirmUserRole::Attorney);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListTrustLedgers::class));

        $test->assertSuccessful();
    }

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
