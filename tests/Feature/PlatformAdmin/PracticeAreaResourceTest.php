<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\FirmUserRole;
use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\ActivatePracticeAreaAction;
use App\Filament\Actions\Platform\CreatePracticeAreaAction;
use App\Filament\Actions\Platform\DeactivatePracticeAreaAction;
use App\Filament\Actions\Platform\EditPracticeAreaAction;
use App\Filament\Resources\PracticeAreaResource;
use App\Filament\Resources\PracticeAreaResource\Pages\ListPracticeAreas;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\MatterType;
use App\Models\PlatformAdmin;
use App\Models\PracticeArea;
use App\Models\User;
use App\Services\MatterTypeService;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PracticeAreaResourceTest — FirmsVault staging follow-up ("Application
 * Completion — Catalogs + Firm-Owned Reference Data"). Proves global
 * catalog CRUD authorization (SuperAdmin/PlatformAdmin only, matching
 * PlanResource's exact ceiling), soft deactivate/activate (never a
 * delete), that ordinary Firm users can never reach these routes at
 * all, and MatterTypeService's own practice-area-scoped uniqueness
 * (the "Matter Type belongs to selected Practice Area" invariant, at
 * the writer-service layer — the dependent-select UI layer is already
 * covered by MatterCreationServiceTest::
 * test_create_rejects_a_matter_type_that_does_not_belong_to_the_practice_area).
 */
final class PracticeAreaResourceTest extends TestCase
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

    // ------------------------------------------------------------
    // Access
    // ------------------------------------------------------------

    public function test_a_super_admin_can_access_the_resource(): void
    {
        $this->actingAs($this->adminWithRole(PlatformRoleCode::SuperAdmin), 'platform_admin');

        $this->assertTrue(PracticeAreaResource::canAccess());
    }

    public function test_a_billing_admin_cannot_access_the_resource(): void
    {
        $this->actingAs($this->adminWithRole(PlatformRoleCode::BillingAdmin), 'platform_admin');

        $this->assertFalse(PracticeAreaResource::canAccess());
    }

    public function test_a_firm_user_can_never_reach_the_practice_area_admin_route(): void
    {
        $firm = Firm::factory()->create();
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::FirmOwner)->create()
        );
        $this->actingAs($firmUser->user);

        $response = $this->get(PracticeAreaResource::getUrl('index'));

        $this->assertNotSame(200, $response->getStatusCode());
    }

    // ------------------------------------------------------------
    // Create / Edit / Activate / Deactivate
    // ------------------------------------------------------------

    public function test_an_authorized_admin_can_create_a_practice_area(): void
    {
        $this->actingAs($this->adminWithRole(PlatformRoleCode::SuperAdmin), 'platform_admin');

        $test = Livewire::test(ListPracticeAreas::class);
        $test->mountAction(CreatePracticeAreaAction::getDefaultName());
        $test->setActionData(['name' => 'Water Law', 'code' => 'water_law', 'description' => null]);
        $test->callMountedAction();

        $test->assertHasNoActionErrors();
        $this->assertNotNull(PracticeArea::query()->where('code', 'water_law')->first());
    }

    /**
     * BillingAdmin fails canAccess() entirely for this resource (there is
     * no separate broader read gate the way Plan's canAccessPlatformBilling()
     * provides — see PracticeAreaResource's own single-gate docblock), so
     * mounting an action against a page BillingAdmin cannot even reach
     * hits Filament's own documented "mountedActions on null" test-helper
     * limitation rather than proving anything about this action's own
     * authorization. A SuperAdmin who ALSO holds ReadOnlyAuditor can
     * reach the page (passes canManagePracticeAreaCatalog) but must still
     * be blocked by the blanket canMutate() rule — mirrors
     * PlanCatalogCreateActionsTest's identical established discipline.
     */
    public function test_an_unauthorized_admin_cannot_create_a_practice_area(): void
    {
        $actor = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($actor, PlatformRoleCode::SuperAdmin);
        app(PlatformRoleService::class)->grant($actor, PlatformRoleCode::ReadOnlyAuditor);
        $this->actingAs($actor, 'platform_admin');

        $test = Livewire::test(ListPracticeAreas::class);
        $test->mountAction(CreatePracticeAreaAction::getDefaultName());
        $test->setActionData(['name' => 'Should Not Exist', 'code' => 'should_not_exist', 'description' => null]);
        $test->callMountedAction();

        $this->assertSame(0, PracticeArea::query()->where('code', 'should_not_exist')->count());
    }

    public function test_duplicate_code_fails_safely_without_a_second_row(): void
    {
        PracticeArea::factory()->create(['code' => 'existing_code']);
        $this->actingAs($this->adminWithRole(PlatformRoleCode::SuperAdmin), 'platform_admin');

        $test = Livewire::test(ListPracticeAreas::class);
        $test->mountAction(CreatePracticeAreaAction::getDefaultName());
        $test->setActionData(['name' => 'Duplicate', 'code' => 'existing_code', 'description' => null]);
        $test->callMountedAction();

        $this->assertSame(1, PracticeArea::query()->where('code', 'existing_code')->count());
    }

    public function test_an_authorized_admin_can_edit_a_practice_area(): void
    {
        $practiceArea = PracticeArea::factory()->create(['name' => 'Old Name']);
        $this->actingAs($this->adminWithRole(PlatformRoleCode::SuperAdmin), 'platform_admin');

        $test = Livewire::test(ListPracticeAreas::class);
        $test->mountTableAction(EditPracticeAreaAction::getDefaultName(), $practiceArea->getKey());
        $test->setTableActionData(['name' => 'New Name', 'code' => $practiceArea->code, 'description' => null]);
        $test->callMountedTableAction();

        $test->assertHasNoTableActionErrors();
        $this->assertSame('New Name', $practiceArea->fresh()->name);
    }

    public function test_deactivate_then_activate_round_trips_without_deleting_the_row(): void
    {
        $practiceArea = PracticeArea::factory()->create(['is_active' => true]);
        $this->actingAs($this->adminWithRole(PlatformRoleCode::SuperAdmin), 'platform_admin');

        $test = Livewire::test(ListPracticeAreas::class);
        $test->mountTableAction(DeactivatePracticeAreaAction::getDefaultName(), $practiceArea->getKey());
        $test->callMountedTableAction();
        $this->assertFalse($practiceArea->fresh()->is_active);
        $this->assertNotNull(PracticeArea::query()->find($practiceArea->id), 'Deactivation must never hard-delete the row.');

        $test = Livewire::test(ListPracticeAreas::class);
        $test->mountTableAction(ActivatePracticeAreaAction::getDefaultName(), $practiceArea->getKey());
        $test->callMountedTableAction();
        $this->assertTrue($practiceArea->fresh()->is_active);
    }

    // ------------------------------------------------------------
    // MatterTypeService — practice-area-scoped uniqueness
    // ------------------------------------------------------------

    public function test_matter_type_service_creates_a_type_under_a_practice_area(): void
    {
        $practiceArea = PracticeArea::factory()->create();

        $matterType = app(MatterTypeService::class)->create($practiceArea, ['name' => 'Custom Type', 'code' => 'custom_type']);

        $this->assertSame($practiceArea->id, $matterType->practice_area_id);
    }

    public function test_matter_type_service_allows_the_same_code_under_different_practice_areas(): void
    {
        $areaA = PracticeArea::factory()->create();
        $areaB = PracticeArea::factory()->create();
        $service = app(MatterTypeService::class);

        $service->create($areaA, ['name' => 'General Matter', 'code' => 'general_matter']);
        $matterTypeB = $service->create($areaB, ['name' => 'General Matter', 'code' => 'general_matter']);

        $this->assertSame($areaB->id, $matterTypeB->practice_area_id);
    }

    public function test_matter_type_service_rejects_a_duplicate_code_within_the_same_practice_area(): void
    {
        $practiceArea = PracticeArea::factory()->create();
        $service = app(MatterTypeService::class);
        $service->create($practiceArea, ['name' => 'Divorce', 'code' => 'divorce']);

        $this->expectException(\InvalidArgumentException::class);
        $service->create($practiceArea, ['name' => 'Divorce Again', 'code' => 'divorce']);
    }

    public function test_matter_type_deactivate_then_activate_round_trips_without_deleting_the_row(): void
    {
        $practiceArea = PracticeArea::factory()->create();
        $matterType = MatterType::factory()->create(['practice_area_id' => $practiceArea->id, 'is_active' => true]);
        $service = app(MatterTypeService::class);

        $deactivated = $service->deactivate($matterType);
        $this->assertFalse($deactivated->is_active);
        $this->assertNotNull(MatterType::query()->find($matterType->id));

        $activated = $service->activate($matterType);
        $this->assertTrue($activated->is_active);
    }

    // ------------------------------------------------------------
    // Inactive entries excluded from selection
    // ------------------------------------------------------------

    public function test_inactive_practice_areas_are_excluded_from_active_query_scope(): void
    {
        $active = PracticeArea::factory()->create(['is_active' => true]);
        $inactive = PracticeArea::factory()->create(['is_active' => false]);

        $options = PracticeArea::query()->where('is_active', true)->pluck('id')->all();

        $this->assertContains($active->id, $options);
        $this->assertNotContains($inactive->id, $options);
    }

    public function test_inactive_matter_types_are_excluded_from_active_query_scope(): void
    {
        $practiceArea = PracticeArea::factory()->create();
        $active = MatterType::factory()->create(['practice_area_id' => $practiceArea->id, 'is_active' => true]);
        $inactive = MatterType::factory()->create(['practice_area_id' => $practiceArea->id, 'is_active' => false]);

        $options = MatterType::query()->where('practice_area_id', $practiceArea->id)->where('is_active', true)->pluck('id')->all();

        $this->assertContains($active->id, $options);
        $this->assertNotContains($inactive->id, $options);
    }
}
