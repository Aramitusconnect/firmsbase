<?php

namespace Tests\Feature\Automation;

use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\AutomationActionExecutionResource;
use App\Filament\Firm\Resources\AutomationActionExecutionResource\Pages\ListAutomationActionExecutions;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * AutomationActionExecutionResourceAccessTest — Completion & Activation
 * Program, Mission 2, finding 2.3. AutomationActionExecutionResource
 * (the Automation "Activity Log," including `last_error` text) had no
 * ->canAccess() override and no Policy existed for
 * App\Models\AutomationActionExecution, so Filament's default (no
 * policy → allowed) let ANY authenticated firm user of any role view
 * it. AutomationActionExecutionPolicy now gates it with the exact same
 * AutomationAccessPolicyService::canManageRules() ceiling
 * (FirmOwner/Attorney/BillingStaff) AutomationRulePolicy already
 * enforces for AutomationRuleResource — this test mirrors
 * AutomationRuleResourceAccessTest's first two cases for that resource.
 */
final class AutomationActionExecutionResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    public function test_an_authorized_role_can_view_the_activity_log_list(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListAutomationActionExecutions::class));

        $test->assertSuccessful();
    }

    public function test_an_unauthorized_role_cannot_view_the_activity_log_list(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Paralegal);

        $response = $this->runWithFirmContext($firm, fn () => $this->get(AutomationActionExecutionResource::getUrl('index')));

        $response->assertForbidden();
    }

    public function test_billing_staff_can_view_the_activity_log_list(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);

        $response = $this->runWithFirmContext($firm, fn () => $this->get(AutomationActionExecutionResource::getUrl('index')));

        $response->assertSuccessful();
    }

    public function test_receptionist_cannot_view_the_activity_log_list(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Receptionist);

        $response = $this->runWithFirmContext($firm, fn () => $this->get(AutomationActionExecutionResource::getUrl('index')));

        $response->assertForbidden();
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
