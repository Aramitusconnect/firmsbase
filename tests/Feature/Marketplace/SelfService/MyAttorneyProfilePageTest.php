<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\SelfService;

use App\Enums\FirmUserRole;
use App\Filament\Firm\Pages\MyAttorneyProfilePage;
use App\Marketplace\Models\DirectoryClaim;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\DirectoryProfileVersion;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Language;
use App\Models\PracticeArea;
use App\Models\SecurityEvent;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * MyAttorneyProfilePageTest — Mission 2 (MyAttorney Marketplace Core),
 * checkpoint 10. Section 60: a claimed Firm manages its marketplace
 * profile from the authenticated Firm app. Modeled directly on
 * FirmSettingsPageAccessTest's established style — every
 * Livewire::test() call against this page is wrapped in
 * runWithFirmContext() because firm_users/security_events both carry
 * FORCE ROW LEVEL SECURITY, and a Livewire AJAX call carries no
 * ambient app.current_firm_id session setting on its own (only the
 * panel's page-LOAD auth middleware establishes that).
 */
final class MyAttorneyProfilePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    public function test_guest_is_redirected_away_from_the_profile_page(): void
    {
        $response = $this->get(MyAttorneyProfilePage::getUrl());

        $response->assertRedirect();
    }

    public function test_a_firm_with_no_claimed_listing_sees_an_empty_state_and_no_save_action(): void
    {
        $firm = $this->actingAsRole(FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function () {
            $test = Livewire::test(MyAttorneyProfilePage::class);
            $test->assertSee('You have not claimed a MyAttorney listing yet.');
            $test->assertDontSee('Save Profile');
        });
    }

    public function test_a_firm_owner_with_a_claimed_listing_sees_the_prefilled_form(): void
    {
        $firm = $this->actingAsRole(FirmUserRole::FirmOwner);
        DirectoryFirm::factory()->create([
            'is_claimed' => true, 'claimed_at' => now(), 'firm_id' => $firm->id,
            'display_name' => 'Acme Legal', 'phone' => '5555550100',
        ]);

        $this->runWithFirmContext($firm, function () {
            $test = Livewire::test(MyAttorneyProfilePage::class);
            $test->assertSet('data.display_name', 'Acme Legal');
            $test->assertSet('data.phone', '5555550100');
            $test->assertSee('Save Profile');
        });
    }

    public function test_save_action_is_hidden_for_every_non_owner_role(): void
    {
        foreach (FirmUserRole::cases() as $role) {
            if ($role === FirmUserRole::FirmOwner) {
                continue;
            }

            $firm = $this->actingAsRole($role);
            DirectoryFirm::factory()->create(['is_claimed' => true, 'claimed_at' => now(), 'firm_id' => $firm->id]);

            $this->runWithFirmContext($firm, function () {
                $test = Livewire::test(MyAttorneyProfilePage::class);
                $test->assertDontSee('Save Profile');
            });
        }
    }

    public function test_firm_owner_can_save_legitimate_changes_including_practice_areas_and_languages(): void
    {
        $firm = $this->actingAsRole(FirmUserRole::FirmOwner);
        $directoryFirm = DirectoryFirm::factory()->create(['is_claimed' => true, 'claimed_at' => now(), 'firm_id' => $firm->id, 'display_name' => 'Old Name']);
        $family = PracticeArea::query()->where('code', 'family-law')->firstOrFail();
        $spanish = Language::factory()->spanish()->create();

        $this->runWithFirmContext($firm, function () use ($family, $spanish) {
            $test = Livewire::test(MyAttorneyProfilePage::class);
            $test->set('data.display_name', 'New Name PLLC');
            $test->set('data.phone', '5555550199');
            $test->set('data.accepting_inquiries', true);
            $test->set('data.practice_area_ids', [$family->id]);
            $test->set('data.language_ids', [$spanish->id]);
            $test->call('save');
            $test->assertHasNoErrors();
        });

        $fresh = $directoryFirm->fresh(['practiceAreas', 'languages']);
        $this->assertSame('New Name PLLC', $fresh->display_name);
        $this->assertSame('5555550199', $fresh->phone);
        $this->assertTrue($fresh->accepting_inquiries);
        $this->assertTrue($fresh->practiceAreas->contains('id', $family->id));
        $this->assertTrue($fresh->languages->contains('id', $spanish->id));
        $this->assertNotNull($fresh->last_confirmed_by_firm_at);
    }

    public function test_save_records_a_profile_version_attributed_to_the_firm_user(): void
    {
        $firm = $this->actingAsRole(FirmUserRole::FirmOwner);
        $firmUser = $this->runWithFirmContext($firm, fn () => FirmUser::query()->where('firm_id', $firm->id)->first());
        $directoryFirm = DirectoryFirm::factory()->create(['is_claimed' => true, 'claimed_at' => now(), 'firm_id' => $firm->id, 'display_name' => 'Old Name']);

        $this->runWithFirmContext($firm, function () {
            $test = Livewire::test(MyAttorneyProfilePage::class);
            $test->set('data.display_name', 'New Name PLLC');
            $test->call('save');
        });

        $version = DirectoryProfileVersion::query()->where('directory_firm_id', $directoryFirm->id)->first();
        $this->assertNotNull($version);
        $this->assertSame('firm_user', $version->actor_type);
        $this->assertSame($firmUser->user_id, $version->actor_id);
        $this->assertArrayHasKey('display_name', $version->changed_fields);
        $this->assertSame('firm_submitted', $version->source->value);
    }

    public function test_save_records_a_firm_user_audit_event(): void
    {
        $firm = $this->actingAsRole(FirmUserRole::FirmOwner);
        $directoryFirm = DirectoryFirm::factory()->create(['is_claimed' => true, 'claimed_at' => now(), 'firm_id' => $firm->id]);

        $this->runWithFirmContext($firm, function () {
            $test = Livewire::test(MyAttorneyProfilePage::class);
            $test->set('data.display_name', 'Updated');
            $test->call('save');
        });

        $event = $this->runWithFirmContext($firm, fn () => SecurityEvent::query()
            ->where('event_type', 'marketplace_profile_updated')
            ->where('firm_id', $firm->id)
            ->first());

        $this->assertNotNull($event);
        $this->assertSame($directoryFirm->id, $event->metadata['directory_firm_id']);
    }

    public function test_non_owner_forcing_save_directly_is_blocked_with_a_403_and_no_mutation(): void
    {
        $firm = $this->actingAsRole(FirmUserRole::Attorney);
        $directoryFirm = DirectoryFirm::factory()->create(['is_claimed' => true, 'claimed_at' => now(), 'firm_id' => $firm->id, 'display_name' => 'Untouched']);

        $this->runWithFirmContext($firm, function () {
            $test = Livewire::test(MyAttorneyProfilePage::class);
            $test->set('data.display_name', 'Smuggled Name');
            $test->call('save')->assertForbidden();
        });

        $this->assertSame('Untouched', $directoryFirm->fresh()->display_name);
    }

    public function test_forging_platform_managed_values_via_data_has_no_effect_on_save(): void
    {
        $otherFirm = Firm::factory()->create();
        $firm = $this->actingAsRole(FirmUserRole::FirmOwner);
        $directoryFirm = DirectoryFirm::factory()->create(['is_claimed' => true, 'claimed_at' => now(), 'firm_id' => $firm->id]);

        $this->runWithFirmContext($firm, function () use ($otherFirm) {
            $test = Livewire::test(MyAttorneyProfilePage::class);
            $test->set('data.is_claimed', false);
            $test->set('data.firm_id', $otherFirm->id);
            $test->set('data.publication_state', 'suspended');
            $test->set('data.display_name', 'Legit Change');
            $test->call('save');
            $test->assertHasNoErrors();
        });

        $fresh = $directoryFirm->fresh();
        $this->assertTrue($fresh->is_claimed, 'is_claimed must never be settable through this page.');
        $this->assertSame($firm->id, $fresh->firm_id, 'firm_id must never be settable through this page.');
        $this->assertNotSame('suspended', $fresh->publication_state->value, 'publication_state must never be settable through this page.');
        $this->assertSame('Legit Change', $fresh->display_name);
    }

    public function test_a_firm_only_ever_sees_and_edits_its_own_directory_listing_never_another_firms(): void
    {
        $firmA = $this->actingAsRole(FirmUserRole::FirmOwner);
        $firmB = Firm::factory()->create();
        $listingA = DirectoryFirm::factory()->create(['is_claimed' => true, 'claimed_at' => now(), 'firm_id' => $firmA->id, 'display_name' => 'Firm A Listing']);
        $listingB = DirectoryFirm::factory()->create(['is_claimed' => true, 'claimed_at' => now(), 'firm_id' => $firmB->id, 'display_name' => 'Firm B Listing']);

        $this->runWithFirmContext($firmA, function () {
            $test = Livewire::test(MyAttorneyProfilePage::class);
            $test->assertSet('data.display_name', 'Firm A Listing');
            $test->set('data.display_name', 'Firm A Updated');
            $test->call('save');
        });

        $this->assertSame('Firm A Updated', $listingA->fresh()->display_name);
        $this->assertSame('Firm B Listing', $listingB->fresh()->display_name, "Firm B's own listing must be completely unaffected.");
    }

    public function test_badges_claim_history_and_offices_are_shown_read_only(): void
    {
        $firm = $this->actingAsRole(FirmUserRole::FirmOwner);
        $firmUser = $this->runWithFirmContext($firm, fn () => FirmUser::query()->where('firm_id', $firm->id)->first());
        $directoryFirm = DirectoryFirm::factory()->create(['is_claimed' => true, 'claimed_at' => now(), 'firm_id' => $firm->id]);
        DirectoryClaim::factory()->forDirectoryFirmAndClaimant($directoryFirm, $firmUser)->approved()->create();

        $this->runWithFirmContext($firm, function () {
            $test = Livewire::test(MyAttorneyProfilePage::class);
            $test->assertSee('Claimed Profile');
        });
    }

    private function actingAsRole(FirmUserRole $role): Firm
    {
        $firm = Firm::factory()->create();
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role($role)->create()
        );

        $this->actingAs($firmUser->user);

        return $firm;
    }
}
