<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\BillingInterval;
use App\Enums\PlanModuleStatus;
use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\AddPlanModuleAction;
use App\Filament\Actions\Platform\CreatePlanAction;
use App\Filament\Actions\Platform\EditPlanAction;
use App\Filament\Resources\PlanAddOnResource\Pages\ListPlanAddOns;
use App\Filament\Resources\PlanResource\Pages\ListPlans;
use App\Models\ModuleCatalog;
use App\Models\Plan;
use App\Models\PlanModule;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use App\Services\TenantContextService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlanCatalogCreateActionsTest — FIRMSVAULT STAGING ADMIN STABILIZATION,
 * Phase 10 required tests #4-13. Authorization, success, and rejection
 * coverage for CreatePlanAction/EditPlanAction/AddPlanModuleAction — the
 * first supported Create workflows for Plans and Plan Modules/Add-ons.
 */
final class PlanCatalogCreateActionsTest extends TestCase
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

    private function activeModule(string $code): ModuleCatalog
    {
        return ModuleCatalog::query()->firstOrCreate(
            ['module_code' => $code],
            ['module_name' => $code, 'category' => 'general', 'is_active' => true],
        );
    }

    // ------------------------------------------------------------
    // CreatePlanAction
    // ------------------------------------------------------------

    public function test_an_authorized_admin_can_create_a_plan(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $test = Livewire::test(ListPlans::class);
        $test->mountAction(CreatePlanAction::getDefaultName());
        $test->setActionData([
            'name' => 'Solo Practice',
            'code' => 'solo-practice',
            'price_cents' => 9900,
            'billing_interval' => BillingInterval::Monthly->value,
            'support_access_level' => 'standard',
            'trial_days' => 14,
            'trial_requires_card' => false,
            'description' => 'Synthetic test plan.',
        ]);
        $test->callMountedAction();

        $test->assertHasNoActionErrors();

        $plan = Plan::query()->where('code', 'solo-practice')->first();
        $this->assertNotNull($plan);
        $this->assertSame('Solo Practice', $plan->name);
        $this->assertSame(9900, $plan->price_cents);
        $this->assertIsInt($plan->price_cents, 'Price must be stored as integer cents, never a float.');

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')->where('event_type', 'plan_created')->where('actor_id', $actor->id)->first()
        );
        $this->assertNotNull($row, 'A security_events audit row must be written for plan creation.');
    }

    public function test_a_read_only_auditor_cannot_create_a_plan_even_with_super_admin(): void
    {
        $actor = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($actor, PlatformRoleCode::SuperAdmin);
        app(PlatformRoleService::class)->grant($actor, PlatformRoleCode::ReadOnlyAuditor);
        $this->actingAs($actor, 'platform_admin');

        $test = Livewire::test(ListPlans::class);
        $test->mountAction(CreatePlanAction::getDefaultName());
        $test->setActionData([
            'name' => 'Should Not Exist',
            'code' => 'should-not-exist',
            'price_cents' => 100,
            'billing_interval' => BillingInterval::Monthly->value,
        ]);
        $test->callMountedAction();

        $this->assertSame(0, Plan::query()->where('code', 'should-not-exist')->count());
    }

    /**
     * BillingAdmin passes the broader read gate (canAccessPlatformBilling
     * — the Plans list itself is reachable) but not the narrower
     * canManagePlatformBilling gate CreatePlanAction actually enforces —
     * see PlatformStaffAccessPolicyService's own PLATFORM_BILLING_MANAGEMENT_ROLES
     * (SuperAdmin/PlatformAdmin only, deliberately excluding BillingAdmin).
     * A role that cannot even reach the list at all (e.g. SalesRep) hits
     * Filament's own documented "mountedActions on null" test-helper
     * limitation for a 403'd page rather than proving anything about
     * this action's own authorization — see ProvisionFirmActionTest's
     * identical established discipline for why BillingAdmin (not
     * SalesRep) is the correct actor for this proof.
     */
    public function test_an_unauthorized_admin_cannot_create_a_plan(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::BillingAdmin);
        $this->actingAs($actor, 'platform_admin');

        $test = Livewire::test(ListPlans::class);
        $test->mountAction(CreatePlanAction::getDefaultName());
        $test->setActionData([
            'name' => 'Should Not Exist Either',
            'code' => 'should-not-exist-either',
            'price_cents' => 100,
            'billing_interval' => BillingInterval::Monthly->value,
        ]);
        $test->callMountedAction();

        $this->assertSame(0, Plan::query()->where('code', 'should-not-exist-either')->count());
    }

    public function test_direct_create_plan_action_invocation_is_denied_independent_of_visibility(): void
    {
        // Mirrors ProvisionFirmActionTest's own established discipline:
        // an admin who can reach the page but cannot manage billing
        // cannot invoke the action directly either, proving the
        // authorization check lives in the action's own closure, not
        // merely in a ->visible() gate.
        $actor = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($actor, PlatformRoleCode::BillingAdmin);
        app(PlatformRoleService::class)->grant($actor, PlatformRoleCode::ReadOnlyAuditor);
        $this->actingAs($actor, 'platform_admin');

        $test = Livewire::test(ListPlans::class);
        $test->mountAction(CreatePlanAction::getDefaultName());
        $test->setActionData([
            'name' => 'Direct Invocation Test',
            'code' => 'direct-invocation-test',
            'price_cents' => 100,
            'billing_interval' => BillingInterval::Monthly->value,
        ]);
        $test->callMountedAction();

        $this->assertSame(0, Plan::query()->where('code', 'direct-invocation-test')->count());
    }

    public function test_duplicate_plan_code_fails_safely_without_a_second_row(): void
    {
        Plan::factory()->create(['code' => 'existing-code']);

        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $test = Livewire::test(ListPlans::class);
        $test->mountAction(CreatePlanAction::getDefaultName());
        $test->setActionData([
            'name' => 'Duplicate Attempt',
            'code' => 'existing-code',
            'price_cents' => 100,
            'billing_interval' => BillingInterval::Monthly->value,
        ]);
        $test->callMountedAction();

        $this->assertSame(1, Plan::query()->where('code', 'existing-code')->count());
    }

    // ------------------------------------------------------------
    // EditPlanAction
    // ------------------------------------------------------------

    public function test_an_authorized_admin_can_edit_a_plan_not_yet_in_use(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $plan = Plan::factory()->create(['name' => 'Old Name', 'price_cents' => 5000]);

        $test = Livewire::test(ListPlans::class);
        $test->mountTableAction(EditPlanAction::getDefaultName(), $plan->getKey());
        $test->setTableActionData([
            'name' => 'New Name',
            'code' => $plan->code,
            'price_cents' => 7500,
            'billing_interval' => $plan->billing_interval->value,
            'support_access_level' => $plan->support_access_level,
            'trial_days' => $plan->trial_days,
            'trial_requires_card' => $plan->trial_requires_card,
            'description' => $plan->description,
        ]);
        $test->callMountedTableAction();

        $test->assertHasNoTableActionErrors();

        $plan->refresh();
        $this->assertSame('New Name', $plan->name);
        $this->assertSame(7500, $plan->price_cents);
    }

    public function test_edit_is_denied_for_a_billing_admin_without_manage_permission(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::BillingAdmin);
        $this->actingAs($actor, 'platform_admin');

        $plan = Plan::factory()->create(['name' => 'Untouched']);

        $test = Livewire::test(ListPlans::class);
        $test->mountTableAction(EditPlanAction::getDefaultName(), $plan->getKey());
        $test->setTableActionData([
            'name' => 'Should Not Apply',
            'code' => $plan->code,
            'price_cents' => $plan->price_cents,
            'billing_interval' => $plan->billing_interval->value,
        ]);
        $test->callMountedTableAction();

        $plan->refresh();
        $this->assertSame('Untouched', $plan->name);
    }

    // ------------------------------------------------------------
    // AddPlanModuleAction
    // ------------------------------------------------------------

    public function test_an_authorized_admin_can_add_a_module_to_a_plan(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $plan = Plan::factory()->create();
        $module = $this->activeModule('reports');

        $test = Livewire::test(ListPlanAddOns::class);
        $test->mountAction(AddPlanModuleAction::getDefaultName());
        $test->setActionData([
            'plan_id' => $plan->id,
            'module_code' => $module->module_code,
            'enabled' => true,
            'is_addon' => true,
        ]);
        $test->callMountedAction();

        $test->assertHasNoActionErrors();

        $planModule = PlanModule::query()->where('plan_id', $plan->id)->where('module_code', $module->module_code)->first();
        $this->assertNotNull($planModule);
        $this->assertTrue($planModule->is_addon);
        $this->assertTrue($planModule->enabled);
        $this->assertSame(PlanModuleStatus::Active, $planModule->status);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')->where('event_type', 'plan_module_added')->where('actor_id', $actor->id)->first()
        );
        $this->assertNotNull($row);
    }

    public function test_arbitrary_module_codes_are_rejected(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $plan = Plan::factory()->create();

        $test = Livewire::test(ListPlanAddOns::class);
        $test->mountAction(AddPlanModuleAction::getDefaultName());
        $test->setActionData([
            'plan_id' => $plan->id,
            'module_code' => 'totally-invented-module-code',
            'enabled' => true,
            'is_addon' => false,
        ]);
        $test->callMountedAction();

        $this->assertSame(0, PlanModule::query()->where('plan_id', $plan->id)->count());
    }

    public function test_duplicate_plan_module_relation_updates_in_place_not_a_second_row(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $plan = Plan::factory()->create();
        $module = $this->activeModule('invoices');

        foreach ([true, false] as $enabled) {
            $test = Livewire::test(ListPlanAddOns::class);
            $test->mountAction(AddPlanModuleAction::getDefaultName());
            $test->setActionData([
                'plan_id' => $plan->id,
                'module_code' => $module->module_code,
                'enabled' => $enabled,
                'is_addon' => true,
            ]);
            $test->callMountedAction();
            $test->assertHasNoActionErrors();
        }

        $this->assertSame(1, PlanModule::query()->where('plan_id', $plan->id)->where('module_code', $module->module_code)->count());
        $this->assertFalse(PlanModule::query()->where('plan_id', $plan->id)->where('module_code', $module->module_code)->first()->enabled);
    }

    public function test_add_module_is_denied_for_a_read_only_auditor(): void
    {
        $actor = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($actor, PlatformRoleCode::SuperAdmin);
        app(PlatformRoleService::class)->grant($actor, PlatformRoleCode::ReadOnlyAuditor);
        $this->actingAs($actor, 'platform_admin');

        $plan = Plan::factory()->create();
        $module = $this->activeModule('email');

        $test = Livewire::test(ListPlanAddOns::class);
        $test->mountAction(AddPlanModuleAction::getDefaultName());
        $test->setActionData([
            'plan_id' => $plan->id,
            'module_code' => $module->module_code,
            'enabled' => true,
            'is_addon' => true,
        ]);
        $test->callMountedAction();

        $this->assertSame(0, PlanModule::query()->where('plan_id', $plan->id)->count());
    }
}
