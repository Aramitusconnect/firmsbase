<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\ArchiveDirectoryAttorneyAction;
use App\Filament\Actions\Platform\PublishDirectoryAttorneyAction;
use App\Filament\Resources\DirectoryAttorneyResource;
use App\Filament\Resources\DirectoryAttorneyResource\Pages\CreateDirectoryAttorney;
use App\Filament\Resources\DirectoryAttorneyResource\Pages\EditDirectoryAttorney;
use App\Filament\Resources\DirectoryAttorneyResource\Pages\ListDirectoryAttorneys;
use App\Filament\Resources\DirectoryAttorneyResource\Pages\ViewDirectoryAttorney;
use App\Marketplace\Enums\DataProvenanceSourceType;
use App\Marketplace\Enums\DirectoryAttorneyFirmRelationshipState;
use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Enums\VerificationDimension;
use App\Marketplace\Enums\VerificationState;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryAttorneyFirm;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\DirectoryVerification;
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
 * DirectoryAttorneyResourceTest — MyAttorney SuperAdmin console
 * professionalization mission (MYAT3). This is the FIRST admin
 * surface ever built for DirectoryAttorney, so this test proves the
 * whole vertical: navigation gate, list/view render, manual create
 * (never auto-verified per this mission's own instruction — "Do not
 * automatically mark an attorney 'verified' simply because an admin
 * created the record"), edit, firm association safe-workflow (old
 * Current relationship becomes Former, never duplicated), publish/
 * unpublish/archive, and authorization parity with DirectoryFirm.
 */
final class DirectoryAttorneyResourceTest extends TestCase
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

    public function test_navigation_is_hidden_for_no_admin_and_visible_for_super_admin(): void
    {
        $this->assertFalse(DirectoryAttorneyResource::canAccess());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');
        $this->assertTrue(DirectoryAttorneyResource::canAccess());
    }

    public function test_guest_is_redirected_from_every_route(): void
    {
        $attorney = DirectoryAttorney::factory()->create();

        $this->get(DirectoryAttorneyResource::getUrl('index'))->assertRedirect($this->adminUrl('/login'));
        $this->get(DirectoryAttorneyResource::getUrl('create'))->assertRedirect($this->adminUrl('/login'));
        $this->get(DirectoryAttorneyResource::getUrl('view', ['record' => $attorney]))->assertRedirect($this->adminUrl('/login'));
        $this->get(DirectoryAttorneyResource::getUrl('edit', ['record' => $attorney]))->assertRedirect($this->adminUrl('/login'));
    }

    public function test_sales_rep_is_forbidden_at_every_route_and_cannot_create_or_edit(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin');
        $attorney = DirectoryAttorney::factory()->create();

        $this->assertFalse(DirectoryAttorneyResource::canCreate());
        $this->assertFalse(DirectoryAttorneyResource::canEdit($attorney));
        $this->get(DirectoryAttorneyResource::getUrl('index'))->assertForbidden();
        $this->get(DirectoryAttorneyResource::getUrl('create'))->assertForbidden();
        $this->get(DirectoryAttorneyResource::getUrl('view', ['record' => $attorney]))->assertForbidden();
    }

    public function test_list_page_renders_real_attorneys(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');
        $attorney = DirectoryAttorney::factory()->create(['name' => 'Pat Listing Attorney']);

        $test = Livewire::test(ListDirectoryAttorneys::class);
        $test->assertSuccessful();
        $test->assertCanSeeTableRecords([$attorney]);
    }

    public function test_super_admin_can_manually_create_an_attorney_with_firm_association(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $firm = DirectoryFirm::factory()->create();
        $practiceArea = PracticeArea::factory()->create(['is_marketplace_visible' => true]);
        $language = Language::factory()->create(['is_active' => true]);

        Livewire::test(CreateDirectoryAttorney::class)
            ->fillForm([
                'name' => 'Jordan Manual Entry',
                'title' => 'Associate',
                'bar_number' => 'P123456',
                'license_jurisdictions' => ['MI', 'OH'],
                'directory_firm_id' => $firm->id,
                'firm_title' => 'Associate',
                'practice_area_ids' => [$practiceArea->id],
                'language_ids' => [$language->id],
                'publication_state' => DirectoryPublicationState::Draft->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $attorney = DirectoryAttorney::query()->where('name', 'Jordan Manual Entry')->firstOrFail();
        $this->assertSame(DirectoryPublicationState::Draft, $attorney->publication_state);
        $this->assertSame(DataProvenanceSourceType::AdminEntered, $attorney->source_type);
        $this->assertSame(['MI', 'OH'], $attorney->license_jurisdictions);
        $this->assertTrue($attorney->practiceAreas->contains($practiceArea));

        $relationship = $attorney->firmRelationships()->first();
        $this->assertNotNull($relationship);
        $this->assertSame($firm->id, $relationship->directory_firm_id);
        $this->assertSame(DirectoryAttorneyFirmRelationshipState::Current, $relationship->relationship_state);
    }

    public function test_manually_created_attorney_is_never_automatically_verified(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        Livewire::test(CreateDirectoryAttorney::class)
            ->fillForm(['name' => 'Never Auto Verified'])
            ->call('create')
            ->assertHasNoFormErrors();

        $attorney = DirectoryAttorney::query()->where('name', 'Never Auto Verified')->firstOrFail();
        $this->assertNull($attorney->last_verified_at);

        $verifiedRow = DB::table('directory_verifications')
            ->where('verifiable_type', DirectoryAttorney::class)
            ->where('verifiable_id', $attorney->id)
            ->exists();
        $this->assertFalse($verifiedRow, 'Manual creation must never write a verification row.');
    }

    public function test_invalid_create_data_is_rejected(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        Livewire::test(CreateDirectoryAttorney::class)
            ->fillForm(['name' => ''])
            ->call('create')
            ->assertHasFormErrors(['name']);
    }

    /**
     * MyAttorney final hardening mission, finding 7. Add Attorney had no
     * duplicate check at all before this mission — proves the new
     * warning renders with its matching reason (finding 12).
     */
    public function test_manual_creation_warns_on_a_likely_duplicate_attorney_with_the_matching_reason(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        DirectoryAttorney::factory()->create(['name' => 'Reason Duplicate Attorney', 'name_normalized' => 'reason duplicate attorney']);

        Livewire::test(CreateDirectoryAttorney::class)
            ->fillForm(['name' => 'Reason Duplicate Attorney'])
            ->assertSee('Possible duplicate')
            ->assertSee('Same normalized name');
    }

    public function test_creating_an_attorney_despite_a_duplicate_requires_an_explicit_reason(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        DirectoryAttorney::factory()->create(['name' => 'Blocking Duplicate Attorney', 'name_normalized' => 'blocking duplicate attorney']);

        Livewire::test(CreateDirectoryAttorney::class)
            ->fillForm(['name' => 'Blocking Duplicate Attorney'])
            ->call('create')
            ->assertHasFormErrors(['duplicate_override_reason']);

        $this->assertSame(1, DirectoryAttorney::query()->where('name', 'Blocking Duplicate Attorney')->count());
    }

    public function test_creating_an_attorney_despite_a_duplicate_with_a_reason_succeeds_and_is_audited(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $existing = DirectoryAttorney::factory()->create(['bar_number' => 'P999999']);

        Livewire::test(CreateDirectoryAttorney::class)
            ->fillForm([
                'name' => 'A Genuinely Different Attorney',
                'bar_number' => 'P999999',
                'duplicate_override_reason' => 'Confirmed with the State Bar — this bar number was reassigned; not the same person.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = DirectoryAttorney::query()->where('name', 'A Genuinely Different Attorney')->first();
        $this->assertNotNull($created, 'Create Anyway must actually create the record once a reason is supplied.');

        $auditRow = DB::table('security_events')
            ->where('event_type', 'marketplace_attorney_created')
            ->where('actor_id', $admin->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($auditRow);

        $decoded = json_decode((string) $auditRow->metadata, true);
        $this->assertArrayHasKey('duplicate_override', $decoded);
        $this->assertSame($existing->id, $decoded['duplicate_override']['matched_directory_attorney_id']);
        $this->assertContains('Same bar number', $decoded['duplicate_override']['matching_reasons']);
        $this->assertStringContainsString('State Bar', $decoded['duplicate_override']['reason']);
    }

    public function test_the_attorney_override_reason_field_never_appears_or_blocks_when_editing(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        DirectoryAttorney::factory()->create(['name' => 'Edit Collision Attorney', 'name_normalized' => 'edit collision attorney']);
        $attorney = DirectoryAttorney::factory()->create(['name' => 'Original Attorney For Edit']);

        Livewire::test(EditDirectoryAttorney::class, ['record' => $attorney->getRouteKey()])
            ->fillForm(['name' => 'Edit Collision Attorney'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Edit Collision Attorney', $attorney->fresh()->name);
    }

    public function test_super_admin_can_edit_an_attorney_and_the_edit_is_audited(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $attorney = DirectoryAttorney::factory()->create(['name' => 'Original Attorney Name']);

        Livewire::test(EditDirectoryAttorney::class, ['record' => $attorney->getRouteKey()])
            ->fillForm(['name' => 'Updated Attorney Name'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Updated Attorney Name', $attorney->fresh()->name);

        $auditRow = DB::table('security_events')
            ->where('event_type', 'marketplace_attorney_updated')
            ->where('actor_id', $admin->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($auditRow);
    }

    /**
     * MyAttorney final hardening mission, finding 8. Verification is
     * multi-dimensional — a `name` edit must invalidate only
     * AttorneyIdentity, never touch a currently-verified
     * AttorneyLicense dimension it has nothing to do with.
     */
    public function test_editing_the_name_invalidates_identity_verification_but_not_license_verification(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $attorney = DirectoryAttorney::factory()->create(['name' => 'Verified Identity Attorney']);
        DirectoryVerification::factory()->forVerifiable($attorney, VerificationDimension::AttorneyIdentity)->verified()->create();
        DirectoryVerification::factory()->forVerifiable($attorney, VerificationDimension::AttorneyLicense)->verified()->create();

        Livewire::test(EditDirectoryAttorney::class, ['record' => $attorney->getRouteKey()])
            ->fillForm(['name' => 'A Renamed Attorney'])
            ->call('save')
            ->assertHasNoFormErrors();

        $identity = DirectoryVerification::query()->where('verifiable_id', $attorney->id)->where('dimension', VerificationDimension::AttorneyIdentity->value)->first();
        $license = DirectoryVerification::query()->where('verifiable_id', $attorney->id)->where('dimension', VerificationDimension::AttorneyLicense->value)->first();

        $this->assertSame(VerificationState::Revoked, $identity->state);
        $this->assertSame(VerificationState::Verified, $license->state, 'A name change must never invalidate the unrelated License dimension.');
    }

    public function test_editing_the_bar_number_invalidates_license_verification_but_not_identity_verification(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $attorney = DirectoryAttorney::factory()->create(['bar_number' => 'P100001']);
        DirectoryVerification::factory()->forVerifiable($attorney, VerificationDimension::AttorneyIdentity)->verified()->create();
        DirectoryVerification::factory()->forVerifiable($attorney, VerificationDimension::AttorneyLicense)->verified()->create();

        Livewire::test(EditDirectoryAttorney::class, ['record' => $attorney->getRouteKey()])
            ->fillForm(['bar_number' => 'P999999'])
            ->call('save')
            ->assertHasNoFormErrors();

        $identity = DirectoryVerification::query()->where('verifiable_id', $attorney->id)->where('dimension', VerificationDimension::AttorneyIdentity->value)->first();
        $license = DirectoryVerification::query()->where('verifiable_id', $attorney->id)->where('dimension', VerificationDimension::AttorneyLicense->value)->first();

        $this->assertSame(VerificationState::Verified, $identity->state, 'A bar number change must never invalidate the unrelated Identity dimension.');
        $this->assertSame(VerificationState::Revoked, $license->state);
    }

    public function test_editing_license_jurisdictions_invalidates_license_verification(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $attorney = DirectoryAttorney::factory()->create(['license_jurisdictions' => ['MI']]);
        DirectoryVerification::factory()->forVerifiable($attorney, VerificationDimension::AttorneyLicense)->verified()->create();

        Livewire::test(EditDirectoryAttorney::class, ['record' => $attorney->getRouteKey()])
            ->fillForm(['license_jurisdictions' => ['MI', 'OH']])
            ->call('save')
            ->assertHasNoFormErrors();

        $license = DirectoryVerification::query()->where('verifiable_id', $attorney->id)->where('dimension', VerificationDimension::AttorneyLicense->value)->first();
        $this->assertSame(VerificationState::Revoked, $license->state);
    }

    public function test_merely_reordering_license_jurisdictions_does_not_invalidate_verification(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $attorney = DirectoryAttorney::factory()->create(['license_jurisdictions' => ['MI', 'OH']]);
        DirectoryVerification::factory()->forVerifiable($attorney, VerificationDimension::AttorneyLicense)->verified()->create();

        Livewire::test(EditDirectoryAttorney::class, ['record' => $attorney->getRouteKey()])
            ->fillForm(['license_jurisdictions' => ['OH', 'MI']])
            ->call('save')
            ->assertHasNoFormErrors();

        $license = DirectoryVerification::query()->where('verifiable_id', $attorney->id)->where('dimension', VerificationDimension::AttorneyLicense->value)->first();
        $this->assertSame(VerificationState::Verified, $license->state, 'A merely-reordered, semantically identical jurisdiction list must never falsely invalidate verification.');
    }

    /**
     * "Do NOT expand functionality" — title/biography are not evidence
     * either dimension is based on, so editing them must never touch
     * verification at all.
     */
    public function test_editing_title_or_biography_never_touches_verification(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $attorney = DirectoryAttorney::factory()->create();
        DirectoryVerification::factory()->forVerifiable($attorney, VerificationDimension::AttorneyIdentity)->verified()->create();
        DirectoryVerification::factory()->forVerifiable($attorney, VerificationDimension::AttorneyLicense)->verified()->create();

        Livewire::test(EditDirectoryAttorney::class, ['record' => $attorney->getRouteKey()])
            ->fillForm(['title' => 'Senior Partner', 'biography' => 'An updated biography.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $identity = DirectoryVerification::query()->where('verifiable_id', $attorney->id)->where('dimension', VerificationDimension::AttorneyIdentity->value)->first();
        $license = DirectoryVerification::query()->where('verifiable_id', $attorney->id)->where('dimension', VerificationDimension::AttorneyLicense->value)->first();

        $this->assertSame(VerificationState::Verified, $identity->state);
        $this->assertSame(VerificationState::Verified, $license->state);
    }

    public function test_editing_sensitive_fields_on_an_unverified_attorney_never_errors_or_writes_a_revoke_event(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $attorney = DirectoryAttorney::factory()->create(['name' => 'Never Verified', 'bar_number' => 'P1']);

        Livewire::test(EditDirectoryAttorney::class, ['record' => $attorney->getRouteKey()])
            ->fillForm(['name' => 'Still Never Verified', 'bar_number' => 'P2'])
            ->call('save')
            ->assertHasNoFormErrors();

        $revokeEvent = DB::table('security_events')->where('event_type', 'marketplace_verification_revoked')->exists();
        $this->assertFalse($revokeEvent, 'Editing a never-verified attorney must never write a revoke event.');
    }

    public function test_the_update_audit_event_records_before_after_and_the_invalidated_dimension(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $attorney = DirectoryAttorney::factory()->create(['bar_number' => 'P100001']);
        DirectoryVerification::factory()->forVerifiable($attorney, VerificationDimension::AttorneyLicense)->verified()->create();

        Livewire::test(EditDirectoryAttorney::class, ['record' => $attorney->getRouteKey()])
            ->fillForm(['bar_number' => 'P999999'])
            ->call('save')
            ->assertHasNoFormErrors();

        $auditRow = DB::table('security_events')
            ->where('event_type', 'marketplace_attorney_updated')
            ->where('actor_id', $admin->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($auditRow);

        $decoded = json_decode((string) $auditRow->metadata, true);
        $this->assertArrayHasKey('correlation_id', $decoded);
        $this->assertSame(['before' => 'P100001', 'after' => 'P999999'], $decoded['sensitive_field_changes']['bar_number']);
        $this->assertSame([['dimension' => 'attorney_license', 'triggering_field' => 'bar_number']], $decoded['verification_invalidated']);

        $revokeRow = DB::table('security_events')
            ->where('event_type', 'marketplace_verification_revoked')
            ->latest('id')
            ->first();
        $revokeMetadata = json_decode((string) $revokeRow->metadata, true);
        $this->assertSame($decoded['correlation_id'], $revokeMetadata['correlation_id'], 'The edit and the resulting revoke must share a correlation ID so they can be linked in review.');
    }

    /**
     * Section 15's pre-save impact preview must only ever appear when
     * the backend will actually enforce it — never a decorative-only
     * warning.
     */
    public function test_the_pre_save_impact_warning_appears_only_when_a_sensitive_field_changes_on_a_verified_attorney(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $attorney = DirectoryAttorney::factory()->create(['bar_number' => 'P100001']);
        DirectoryVerification::factory()->forVerifiable($attorney, VerificationDimension::AttorneyLicense)->verified()->create();

        $test = Livewire::test(EditDirectoryAttorney::class, ['record' => $attorney->getRouteKey()]);
        $test->assertDontSee('will invalidate the current license verification');

        $test->fillForm(['bar_number' => 'P999999']);
        $test->assertSee('will invalidate the current license verification');
    }

    public function test_the_pre_save_impact_warning_never_appears_on_create(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        Livewire::test(CreateDirectoryAttorney::class)
            ->fillForm(['name' => 'Brand New Attorney', 'bar_number' => 'P1'])
            ->assertDontSee('will invalidate');
    }

    public function test_associating_with_a_new_firm_ends_the_prior_current_relationship_rather_than_duplicating(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $attorney = DirectoryAttorney::factory()->create();
        $oldFirm = DirectoryFirm::factory()->create();
        $newFirm = DirectoryFirm::factory()->create();
        DirectoryAttorneyFirm::factory()->forAttorneyAndFirm($attorney, $oldFirm)->create();

        $test = Livewire::test(ViewDirectoryAttorney::class, ['record' => $attorney->getRouteKey()]);
        $test->mountAction('associateDirectoryAttorneyWithFirm');
        $test->setActionData(['directory_firm_id' => $newFirm->id, 'firm_title' => 'Partner']);
        $test->callMountedAction();
        $test->assertHasNoActionErrors();

        $relationships = $attorney->firmRelationships()->get();
        $this->assertCount(2, $relationships);

        $old = $relationships->firstWhere('directory_firm_id', $oldFirm->id);
        $new = $relationships->firstWhere('directory_firm_id', $newFirm->id);
        $this->assertSame(DirectoryAttorneyFirmRelationshipState::Former, $old->relationship_state);
        $this->assertSame(DirectoryAttorneyFirmRelationshipState::Current, $new->relationship_state);
    }

    public function test_publish_action_transitions_a_draft_attorney_to_published(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $attorney = DirectoryAttorney::factory()->draft()->create();

        $test = Livewire::test(ListDirectoryAttorneys::class);
        $test->assertTableActionVisible(PublishDirectoryAttorneyAction::getDefaultName(), $attorney);
        $test->callTableAction(PublishDirectoryAttorneyAction::getDefaultName(), $attorney);
        $test->assertHasNoTableActionErrors();

        $this->assertSame(DirectoryPublicationState::Published, $attorney->fresh()->publication_state);

        $auditRow = DB::table('security_events')
            ->where('event_type', 'marketplace_attorney_published')
            ->where('actor_id', $admin->id)
            ->exists();
        $this->assertTrue($auditRow);
    }

    public function test_archive_action_transitions_publication_state_and_is_audited(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $attorney = DirectoryAttorney::factory()->create();

        $test = Livewire::test(ListDirectoryAttorneys::class);
        $test->callTableAction(ArchiveDirectoryAttorneyAction::getDefaultName(), $attorney);
        $test->assertHasNoTableActionErrors();

        $this->assertSame(DirectoryPublicationState::Archived, $attorney->fresh()->publication_state);
    }
}
