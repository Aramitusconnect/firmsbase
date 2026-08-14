<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\ApproveCorrectionRequestAction;
use App\Filament\Actions\Platform\CreateInternalCorrectionRequestAction;
use App\Filament\Actions\Platform\MarkCorrectionUnderReviewAction;
use App\Filament\Actions\Platform\ResolveCorrectionRequestAction;
use App\Filament\Resources\DirectoryCorrectionRequestResource;
use App\Filament\Resources\DirectoryCorrectionRequestResource\Pages\ListDirectoryCorrectionRequests;
use App\Filament\Resources\DirectoryCorrectionRequestResource\Pages\ViewDirectoryCorrectionRequest;
use App\Marketplace\Enums\CorrectionState;
use App\Marketplace\Enums\CorrectionType;
use App\Marketplace\Models\DirectoryCorrectionRequest;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\DirectoryProfileVersion;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DirectoryCorrectionWorkflowTest — MyAttorney SuperAdmin console
 * professionalization mission (MYAT5). Proves: the newly-wired
 * Mark Under Review action, the current-vs-requested comparison
 * rendering, the upgraded Resolve action actually applying field
 * changes (MarketplaceCorrectionService::resolve()'s $fieldChanges
 * parameter existed since Mission 2 but was never passed before this
 * mission), and the new "Create Request" internal-submission action.
 */
final class DirectoryCorrectionWorkflowTest extends TestCase
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

    public function test_mark_under_review_transitions_a_pending_request(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $request = DirectoryCorrectionRequest::factory()->create(['state' => CorrectionState::Pending]);

        $test = Livewire::test(ViewDirectoryCorrectionRequest::class, ['record' => $request->uuid]);
        $test->assertActionVisible(MarkCorrectionUnderReviewAction::getDefaultName());
        $test->mountAction(MarkCorrectionUnderReviewAction::getDefaultName());
        $test->callMountedAction();
        $test->assertHasNoActionErrors();

        $this->assertSame(CorrectionState::UnderReview, $request->fresh()->state);
    }

    public function test_comparison_view_shows_reported_issue_next_to_current_listing_data(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $firm = DirectoryFirm::factory()->create(['display_name' => 'Current Value Firm', 'phone' => '5551234567']);
        $request = DirectoryCorrectionRequest::factory()->create([
            'directory_firm_id' => $firm->id,
            'description' => 'The phone number listed is outdated.',
        ]);

        $response = $this->get(DirectoryCorrectionRequestResource::getUrl('view', ['record' => $request]));

        $response->assertOk();
        $response->assertSee('Current Value Firm');
        $response->assertSee('5551234567');
        $response->assertSee('The phone number listed is outdated.');
    }

    public function test_resolve_action_applies_field_changes_and_records_a_profile_version(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $firm = DirectoryFirm::factory()->create(['phone' => '5550000000']);
        $request = DirectoryCorrectionRequest::factory()->create([
            'directory_firm_id' => $firm->id,
            'correction_type' => CorrectionType::IncorrectPhone,
            'state' => CorrectionState::Approved,
        ]);

        $test = Livewire::test(ViewDirectoryCorrectionRequest::class, ['record' => $request->uuid]);
        $test->mountAction(ResolveCorrectionRequestAction::getDefaultName());
        $test->setActionData([
            'display_name' => $firm->display_name,
            'phone' => '5559998888',
            'website' => $firm->website,
            'public_email' => $firm->public_email,
            'description' => $firm->description,
            'resolution_notes' => 'Updated phone number per report.',
        ]);
        $test->callMountedAction();
        $test->assertHasNoActionErrors();

        $this->assertSame('5559998888', $firm->fresh()->phone);
        $this->assertSame(CorrectionState::Resolved, $request->fresh()->state);

        $version = DirectoryProfileVersion::query()->where('directory_firm_id', $firm->id)->latest('id')->first();
        $this->assertNotNull($version);
        $this->assertArrayHasKey('phone', $version->changed_fields);
        $this->assertSame('5559998888', $version->changed_fields['phone']);
    }

    public function test_resolve_action_leaves_unchanged_fields_untouched(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $firm = DirectoryFirm::factory()->create(['display_name' => 'Untouched Firm Name', 'phone' => '5551112222']);
        $request = DirectoryCorrectionRequest::factory()->create([
            'directory_firm_id' => $firm->id,
            'state' => CorrectionState::Approved,
        ]);

        $test = Livewire::test(ViewDirectoryCorrectionRequest::class, ['record' => $request->uuid]);
        $test->mountAction(ResolveCorrectionRequestAction::getDefaultName());
        $test->setActionData([
            'display_name' => $firm->display_name,
            'phone' => $firm->phone,
            'website' => $firm->website,
            'public_email' => $firm->public_email,
            'description' => $firm->description,
            'resolution_notes' => 'No actual change needed, just closing out.',
        ]);
        $test->callMountedAction();
        $test->assertHasNoActionErrors();

        $this->assertSame('Untouched Firm Name', $firm->fresh()->display_name);
        $this->assertSame('5551112222', $firm->fresh()->phone);
        $this->assertSame(0, DirectoryProfileVersion::query()->where('directory_firm_id', $firm->id)->count());
    }

    public function test_super_admin_can_create_an_internal_correction_request(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $admin->update(['name' => 'Reviewer Admin']);
        $this->actingAs($admin, 'platform_admin');

        $firm = DirectoryFirm::factory()->create();

        $test = Livewire::test(ListDirectoryCorrectionRequests::class);
        $test->mountAction(CreateInternalCorrectionRequestAction::getDefaultName());
        $test->setActionData([
            'directory_firm_id' => $firm->id,
            'correction_type' => CorrectionType::FirmClosed->value,
            'description' => 'Caller reported the firm closed last month.',
        ]);
        $test->callMountedAction();
        $test->assertHasNoActionErrors();

        $request = DirectoryCorrectionRequest::query()->where('directory_firm_id', $firm->id)->latest('id')->first();
        $this->assertNotNull($request);
        $this->assertSame(CorrectionState::Pending, $request->state);
        $this->assertStringContainsString('Admin/Internal', $request->reporter_name);
        $this->assertStringContainsString('Reviewer Admin', $request->reporter_name);

        $auditRow = DB::table('security_events')
            ->where('event_type', 'marketplace_correction_created_internally')
            ->where('actor_id', $admin->id)
            ->exists();
        $this->assertTrue($auditRow);
    }

    public function test_approve_action_still_works_alongside_the_new_actions(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $request = DirectoryCorrectionRequest::factory()->create(['state' => CorrectionState::Pending]);

        $test = Livewire::test(ViewDirectoryCorrectionRequest::class, ['record' => $request->uuid]);
        $test->mountAction(ApproveCorrectionRequestAction::getDefaultName());
        $test->callMountedAction();
        $test->assertHasNoActionErrors();

        $this->assertSame(CorrectionState::Approved, $request->fresh()->state);
    }

    public function test_sales_rep_is_forbidden_from_the_view_route(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin');

        $request = DirectoryCorrectionRequest::factory()->create();

        $this->get(DirectoryCorrectionRequestResource::getUrl('view', ['record' => $request]))->assertForbidden();
    }
}
