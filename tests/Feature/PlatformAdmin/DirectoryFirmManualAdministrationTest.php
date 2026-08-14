<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Filament\Resources\DirectoryFirmResource;
use App\Filament\Resources\DirectoryFirmResource\Pages\CreateDirectoryFirm;
use App\Filament\Resources\DirectoryFirmResource\Pages\EditDirectoryFirm;
use App\Marketplace\Enums\DataProvenanceSourceType;
use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\DirectoryProfileVersion;
use App\Models\Language;
use App\Models\PlatformAdmin;
use App\Models\PracticeArea;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DirectoryFirmManualAdministrationTest — MyAttorney SuperAdmin console
 * professionalization mission (MYAT2). Proves the newly added manual
 * "Add Firm"/"Edit Firm" capability: SuperAdmin can create a draft
 * listing with office/practice-area/language associations, publish
 * through the existing PublishDirectoryFirmAction (not a bypass baked
 * into Create), edits are validated and audited, provenance is always
 * stamped AdminEntered, and a non-governance role is blocked from both
 * the Create/Edit routes and canCreate()/canEdit() themselves.
 */
final class DirectoryFirmManualAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    public function test_super_admin_can_manually_create_a_draft_firm_with_office_and_associations(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $practiceArea = PracticeArea::factory()->create(['is_marketplace_visible' => true]);
        $language = Language::factory()->create(['is_active' => true]);

        $test = Livewire::test(CreateDirectoryFirm::class);
        $test->fillForm([
            'display_name' => 'Manual Entry Law Group',
            'phone' => '5555550100',
            'website' => 'https://manualentrylaw.example.com',
            'public_email' => 'contact@manualentrylaw.example.com',
            'description' => 'A firm entered directly by SuperAdmin.',
            'accepting_inquiries' => true,
            'address_line1' => '123 Main St',
            'city' => 'Detroit',
            'state' => 'MI',
            'postal_code' => '48226',
            'country' => 'US',
            'practice_area_ids' => [$practiceArea->id],
            'language_ids' => [$language->id],
            'publication_state' => DirectoryPublicationState::Draft->value,
        ]);
        $test->call('create');
        $test->assertHasNoFormErrors();

        $firm = DirectoryFirm::query()->where('display_name', 'Manual Entry Law Group')->first();
        $this->assertNotNull($firm);
        $this->assertSame(DirectoryPublicationState::Draft, $firm->publication_state);
        $this->assertSame(DataProvenanceSourceType::AdminEntered, $firm->source_type);
        $this->assertFalse($firm->is_claimed);
        $this->assertFalse($firm->is_marketplace_member);
        $this->assertTrue($firm->practiceAreas->contains($practiceArea));
        $this->assertTrue($firm->languages->contains($language));

        $office = $firm->offices()->where('is_primary', true)->first();
        $this->assertNotNull($office);
        $this->assertSame('Detroit', $office->city);
        $this->assertSame('MI', $office->state);
    }

    public function test_creating_a_firm_does_not_automatically_publish_it_publicly(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        Livewire::test(CreateDirectoryFirm::class)
            ->fillForm(['display_name' => 'Draft By Default Firm'])
            ->call('create')
            ->assertHasNoFormErrors();

        $firm = DirectoryFirm::query()->where('display_name', 'Draft By Default Firm')->firstOrFail();
        $this->assertFalse($firm->isPubliclyVisible());
    }

    public function test_invalid_create_data_is_rejected(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        Livewire::test(CreateDirectoryFirm::class)
            ->fillForm(['display_name' => '', 'website' => 'not-a-url'])
            ->call('create')
            ->assertHasFormErrors(['display_name']);
    }

    public function test_manual_creation_warns_on_a_likely_duplicate(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        DirectoryFirm::factory()->create(['display_name' => 'Existing Duplicate Firm', 'name_normalized' => 'existing duplicate firm']);

        $test = Livewire::test(CreateDirectoryFirm::class);
        $test->fillForm(['display_name' => 'Existing Duplicate Firm']);

        $test->assertSee('Possible duplicate');
    }

    public function test_super_admin_can_edit_a_firm_and_the_edit_is_audited_and_versioned(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $firm = DirectoryFirm::factory()->create(['display_name' => 'Original Name', 'phone' => '1110000000']);

        Livewire::test(EditDirectoryFirm::class, ['record' => $firm->getRouteKey()])
            ->fillForm(['display_name' => 'Updated Name'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $firm->fresh();
        $this->assertSame('Updated Name', $fresh->display_name);

        $version = DirectoryProfileVersion::query()->where('directory_firm_id', $firm->id)->latest('id')->first();
        $this->assertNotNull($version, 'An edit must be recorded via MarketplaceProfileVersionService.');
        $this->assertSame('platform_admin', $version->actor_type);
        $this->assertArrayHasKey('display_name', $version->changed_fields);

        $auditRow = DB::table('security_events')
            ->where('event_type', 'marketplace_firm_updated')
            ->where('actor_id', $admin->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($auditRow, 'An edit must write a security_events audit row.');
    }

    public function test_editing_a_firm_never_touches_claimed_verified_or_member_status(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $firm = DirectoryFirm::factory()->claimed()->create(['is_marketplace_member' => true]);

        Livewire::test(EditDirectoryFirm::class, ['record' => $firm->getRouteKey()])
            ->fillForm(['display_name' => 'Still Claimed Firm'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $firm->fresh();
        $this->assertTrue($fresh->is_claimed);
        $this->assertTrue($fresh->is_marketplace_member);
    }

    public function test_a_created_firm_publishes_through_the_existing_authorized_action_not_automatically(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        Livewire::test(CreateDirectoryFirm::class)
            ->fillForm(['display_name' => 'Publish Path Firm', 'publication_state' => DirectoryPublicationState::Draft->value])
            ->call('create')
            ->assertHasNoFormErrors();

        $firm = DirectoryFirm::query()->where('display_name', 'Publish Path Firm')->firstOrFail();
        $this->assertSame(DirectoryPublicationState::Draft, $firm->publication_state);

        // Publication itself is a separate, already-tested, already-audited
        // action (PublishDirectoryFirmActionTest via DirectoryFirmResourceTest)
        // — this test only proves Create does not silently do that step
        // itself.
        $this->assertNull($firm->firm_id);
    }

    public function test_sales_rep_cannot_create_or_edit_a_directory_firm(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin');

        $this->assertFalse(DirectoryFirmResource::canCreate());

        $firm = DirectoryFirm::factory()->create();
        $this->assertFalse(DirectoryFirmResource::canEdit($firm));

        $this->get(DirectoryFirmResource::getUrl('create'))->assertForbidden();
        $this->get(DirectoryFirmResource::getUrl('edit', ['record' => $firm]))->assertForbidden();
    }

    public function test_guest_cannot_reach_the_create_or_edit_routes(): void
    {
        $firm = DirectoryFirm::factory()->create();

        $this->get(DirectoryFirmResource::getUrl('create'))->assertRedirect($this->adminUrl('/login'));
        $this->get(DirectoryFirmResource::getUrl('edit', ['record' => $firm]))->assertRedirect($this->adminUrl('/login'));
    }
}
