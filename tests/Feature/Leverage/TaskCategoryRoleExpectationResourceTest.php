<?php

namespace Tests\Feature\Leverage;

use App\Enums\FirmUserRole;
use App\Enums\TaskWorkCategory;
use App\Filament\Firm\Resources\TaskCategoryRoleExpectationResource;
use App\Filament\Firm\Resources\TaskCategoryRoleExpectationResource\Pages\CreateTaskCategoryRoleExpectation;
use App\Filament\Firm\Resources\TaskCategoryRoleExpectationResource\Pages\ListTaskCategoryRoleExpectations;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TaskCategoryRoleExpectation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TaskCategoryRoleExpectationResourceTest — Leverage Ratio Optimizer,
 * item 8/26. Mirrors MatterBudgetTemplateResourceAccessTest's own
 * shape: authorized management roles can view/create/edit, an
 * operational-only role is forbidden, and the create form actually
 * persists through StaffingPolicyService (never a bare Eloquent save).
 */
final class TaskCategoryRoleExpectationResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    public function test_an_authorized_role_can_view_the_staffing_policies_list(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListTaskCategoryRoleExpectations::class));

        $test->assertSuccessful();
    }

    public function test_an_unauthorized_role_cannot_view_the_staffing_policies_list(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Paralegal);

        $response = $this->runWithFirmContext($firm, fn () => $this->get(TaskCategoryRoleExpectationResource::getUrl('index')));

        $response->assertForbidden();
    }

    public function test_an_unauthorized_role_cannot_open_the_edit_page(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Receptionist);
        $expectation = $this->runWithFirmContext($firm, fn () => TaskCategoryRoleExpectation::factory()->forFirm($firm)->create());

        $response = $this->runWithFirmContext($firm, fn () => $this->get(TaskCategoryRoleExpectationResource::getUrl('edit', ['record' => $expectation])));

        $response->assertForbidden();
    }

    public function test_cross_firm_edit_access_is_refused(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $expectationB = $this->runWithFirmContext($firmB, fn () => TaskCategoryRoleExpectation::factory()->forFirm($firmB)->create());

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(TaskCategoryRoleExpectationResource::getUrl('edit', ['record' => $expectationB])));

        $response->assertNotFound();
    }

    public function test_creating_a_staffing_policy_through_the_form_persists_via_the_service(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function () use ($firm) {
            Livewire::test(CreateTaskCategoryRoleExpectation::class)
                ->fillForm([
                    'task_category' => TaskWorkCategory::DocumentFollowUp->value,
                    'recommended_roles' => [FirmUserRole::Paralegal->value],
                    'notes' => 'Routine follow-up work.',
                ])
                ->call('create')
                ->assertHasNoFormErrors();

            $expectation = TaskCategoryRoleExpectation::query()->where('firm_id', $firm->id)->first();
            $this->assertNotNull($expectation);
            $this->assertSame(TaskWorkCategory::DocumentFollowUp->value, $expectation->task_category);
            $this->assertSame([FirmUserRole::Paralegal->value], $expectation->recommended_roles_json);
        });
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
