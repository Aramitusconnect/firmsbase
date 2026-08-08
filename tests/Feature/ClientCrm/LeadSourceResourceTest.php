<?php

declare(strict_types=1);

namespace Tests\Feature\ClientCrm;

use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\LeadSourceResource;
use App\Filament\Firm\Resources\LeadSourceResource\Actions\DeactivateLeadSourceAction;
use App\Filament\Firm\Resources\LeadSourceResource\Actions\ReactivateLeadSourceAction;
use App\Filament\Firm\Resources\LeadSourceResource\Pages\CreateLeadSource;
use App\Filament\Firm\Resources\LeadSourceResource\Pages\EditLeadSource;
use App\Filament\Firm\Resources\LeadSourceResource\Pages\ListLeadSources;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\LeadSource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * LeadSourceResourceTest — FirmsVault staging follow-up ("Application
 * Completion — Catalogs + Firm-Owned Reference Data"). "Firm
 * Management → Lead Sources". Proves role gating (the same INTAKE_ROLES
 * ceiling ClientCrmAccessPolicyService::canManageContact() already
 * uses — FirmOwner/Attorney/Paralegal/LegalAssistant/Receptionist;
 * BillingStaff excluded), real service-mediated create/edit/deactivate/
 * reactivate, and tenant isolation (FORCE RLS on lead_sources).
 */
final class LeadSourceResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('firm'));
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

    // ------------------------------------------------------------
    // Role gating
    // ------------------------------------------------------------

    public function test_receptionist_can_access_but_billing_staff_cannot(): void
    {
        $firmA = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::Receptionist);
        $this->assertTrue(LeadSourceResource::canAccess());

        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmB, FirmUserRole::BillingStaff);
        $this->assertFalse(LeadSourceResource::canAccess());
    }

    // ------------------------------------------------------------
    // Create / Edit — real service-mediated writes
    // ------------------------------------------------------------

    public function test_create_persists_via_lead_source_service(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = Livewire::test(CreateLeadSource::class);
        $test->fillForm(['name' => 'Website', 'code' => 'website']);
        $test->call('create');

        $test->assertHasNoFormErrors();
        $this->runWithFirmContext($firm, function () use ($firm): void {
            $this->assertNotNull(LeadSource::query()->where('firm_id', $firm->id)->where('code', 'website')->first());
        });
    }

    public function test_duplicate_code_within_the_same_firm_fails_safely(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $this->runWithFirmContext($firm, fn () => LeadSource::factory()->forFirm($firm)->create(['code' => 'website']));

        $test = Livewire::test(CreateLeadSource::class);
        $test->fillForm(['name' => 'Website Again', 'code' => 'website']);
        $test->call('create');
        $test->assertHasFormErrors(['code']);

        $count = $this->runWithFirmContext($firm, fn () => LeadSource::query()->where('firm_id', $firm->id)->where('code', 'website')->count());
        $this->assertSame(1, $count);
    }

    public function test_edit_persists_a_rename_via_the_service(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $leadSource = $this->runWithFirmContext($firm, fn () => LeadSource::factory()->forFirm($firm)->create(['name' => 'Old Name']));

        $this->runWithFirmContext($firm, function () use ($leadSource): void {
            $test = Livewire::test(EditLeadSource::class, ['record' => $leadSource->getRouteKey()]);
            $test->fillForm(['name' => 'New Name', 'code' => $leadSource->code]);
            $test->call('save');
            $test->assertHasNoFormErrors();
        });

        $fresh = $this->runWithFirmContext($firm, fn () => LeadSource::query()->find($leadSource->id));
        $this->assertSame('New Name', $fresh->name);
    }

    public function test_deactivate_then_reactivate_round_trips_without_deleting_the_row(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $leadSource = $this->runWithFirmContext($firm, fn () => LeadSource::factory()->forFirm($firm)->create(['is_active' => true]));

        $this->runWithFirmContext($firm, function () use ($leadSource): void {
            $test = Livewire::test(ListLeadSources::class);
            $test->mountTableAction(DeactivateLeadSourceAction::getDefaultName(), $leadSource->getKey());
            $test->callMountedTableAction();
        });
        $deactivated = $this->runWithFirmContext($firm, fn () => LeadSource::query()->find($leadSource->id));
        $this->assertFalse($deactivated->is_active);
        $this->assertNotNull($deactivated, 'Deactivation must never hard-delete the row.');

        $this->runWithFirmContext($firm, function () use ($leadSource): void {
            $test = Livewire::test(ListLeadSources::class);
            $test->mountTableAction(ReactivateLeadSourceAction::getDefaultName(), $leadSource->getKey());
            $test->callMountedTableAction();
        });
        $reactivated = $this->runWithFirmContext($firm, fn () => LeadSource::query()->find($leadSource->id));
        $this->assertTrue($reactivated->is_active);
    }

    // ------------------------------------------------------------
    // Tenant isolation
    // ------------------------------------------------------------

    public function test_list_page_shows_only_this_firms_lead_sources(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $sourceA = $this->runWithFirmContext($firmA, fn () => LeadSource::factory()->forFirm($firmA)->create());
        $this->runWithFirmContext($firmB, fn () => LeadSource::factory()->forFirm($firmB)->create());

        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firmA, fn () => Livewire::test(ListLeadSources::class));
        $test->assertCanSeeTableRecords([$sourceA]);
    }

    public function test_direct_url_guess_of_another_firms_lead_source_never_succeeds(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $sourceB = $this->runWithFirmContext($firmB, fn () => LeadSource::factory()->forFirm($firmB)->create());

        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(LeadSourceResource::getUrl('edit', ['record' => $sourceB->getRouteKey()])));

        $this->assertNotSame(200, $response->getStatusCode());
    }
}
