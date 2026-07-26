<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlanModuleStatus;
use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\RetirePlanModuleAction;
use App\Filament\Actions\Platform\SetPlanModuleEnabledAction;
use App\Filament\Resources\PlanAddOnResource;
use App\Filament\Resources\PlanAddOnResource\Pages\ListPlanAddOns;
use App\Filament\Resources\PlanAddOnResource\Pages\ViewPlanAddOn;
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
 * PlanAddOnResourceTest — Phase 3 (FirmsVault Platform Admin Control
 * Center, "Billing and Commercial Administration"). Navigation
 * visibility, route-level authorization, the is_addon = true scoping
 * (the one thing that distinguishes this Resource from a general
 * plan-modules list), filters, deterministic ordering, bounded
 * pagination, and the Enable/Disable/Retire actions' full lifecycle.
 */
final class PlanAddOnResourceTest extends TestCase
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

    private function planModule(Plan $plan, array $overrides = []): PlanModule
    {
        $moduleCode = ModuleCatalog::factory()->create()->module_code;

        return PlanModule::factory()->forPlan($plan)->forModuleCode($moduleCode)->create($overrides);
    }

    // --- Navigation visibility ---

    public function test_navigation_is_hidden_when_no_admin_is_authenticated(): void
    {
        $this->assertFalse(PlanAddOnResource::canAccess());
    }

    public function test_navigation_is_visible_for_a_super_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->assertTrue(PlanAddOnResource::canAccess());
    }

    // --- Route-level authorization ---

    public function test_guest_is_redirected_from_the_add_ons_list(): void
    {
        $this->get(PlanAddOnResource::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'platform_admin')->get(PlanAddOnResource::getUrl())->assertForbidden();
    }

    // --- is_addon scoping (the core reason this Resource exists) ---

    public function test_only_add_on_flagged_plan_modules_appear_in_the_list(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $plan = Plan::factory()->create();
        $addOn = $this->planModule($plan, ['is_addon' => true]);
        $bundled = $this->planModule($plan, ['is_addon' => false]);

        $test = Livewire::test(ListPlanAddOns::class);

        $test->assertCanSeeTableRecords([$addOn]);
        $test->assertCanNotSeeTableRecords([$bundled]);
    }

    // --- Catalog-only-effect disclosure ---

    public function test_the_empty_state_discloses_the_catalog_only_effect(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(PlanAddOnResource::getUrl());
        $response->assertOk();
        $response->assertSee('do not immediately change any firm', false);
    }

    // --- Filters ---

    public function test_plan_filter_narrows_the_list(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $planA = Plan::factory()->create();
        $planB = Plan::factory()->create();
        $addOnA = $this->planModule($planA, ['is_addon' => true]);
        $addOnB = $this->planModule($planB, ['is_addon' => true]);

        $test = Livewire::test(ListPlanAddOns::class);
        $test->filterTable('plan_id', $planA->id);

        $test->assertCanSeeTableRecords([$addOnA]);
        $test->assertCanNotSeeTableRecords([$addOnB]);
    }

    public function test_enabled_ternary_filter_narrows_the_list(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $plan = Plan::factory()->create();
        $enabled = $this->planModule($plan, ['is_addon' => true, 'enabled' => true]);
        $disabled = $this->planModule($plan, ['is_addon' => true, 'enabled' => false]);

        $test = Livewire::test(ListPlanAddOns::class);
        $test->filterTable('enabled', true);

        $test->assertCanSeeTableRecords([$enabled]);
        $test->assertCanNotSeeTableRecords([$disabled]);
    }

    // --- Deterministic ordering ---

    public function test_orders_deterministically_when_module_code_ties(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        // Tied module_code across 5 DIFFERENT plans, not one plan — a
        // real tie on the sort column requires duplicate module_code
        // values, but (plan_id, module_code) carries a real unique
        // constraint (confirmed by the initial run of this test failing
        // with SQLSTATE 23505 against a single shared plan_id). Using a
        // distinct Plan per row satisfies uniqueness while still tying
        // the module_code sort key exactly the way the assertion below
        // needs.
        $moduleCode = ModuleCatalog::factory()->create()->module_code;
        $rows = collect(range(1, 5))->map(fn (): PlanModule => PlanModule::factory()
            ->forPlan(Plan::factory()->create())
            ->forModuleCode($moduleCode)
            ->addon()
            ->create());

        $first = Livewire::test(ListPlanAddOns::class)->instance()->getFilteredSortedTableQuery()->pluck('id')->all();
        $second = Livewire::test(ListPlanAddOns::class)->instance()->getFilteredSortedTableQuery()->pluck('id')->all();

        $this->assertSame($first, $second, 'Tied module_code rows must order identically across repeated calls.');
        $this->assertSame($rows->sortBy('id')->pluck('id')->values()->all(), $first);
    }

    // --- Bounded pagination ---

    public function test_the_list_is_paginated(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $plan = Plan::factory()->create();
        foreach (range(1, 30) as $i) {
            $this->planModule($plan, ['is_addon' => true]);
        }

        $test = Livewire::test(ListPlanAddOns::class);
        $test->assertSuccessful();

        $this->assertLessThanOrEqual(25, $test->instance()->getTableRecords()->count());
    }

    // --- Enable/Disable action lifecycle ---

    public function test_disable_action_disables_an_enabled_add_on_and_writes_an_audit_event(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $plan = Plan::factory()->create();
        $addOn = $this->planModule($plan, ['is_addon' => true, 'enabled' => true]);

        $test = Livewire::test(ViewPlanAddOn::class, ['record' => $addOn->uuid]);
        $test->mountAction(SetPlanModuleEnabledAction::getDefaultName());
        $test->callMountedAction();

        $test->assertHasNoActionErrors();

        $addOn->refresh();
        $this->assertFalse($addOn->enabled);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'plan_module_disabled')
                ->where('actor_id', $actor->id)
                ->first()
        );
        $this->assertNotNull($row, 'A security_events audit row must be written for the Disable action.');
    }

    public function test_enable_action_enables_a_disabled_add_on_and_writes_an_audit_event(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::PlatformAdmin);
        $this->actingAs($actor, 'platform_admin');

        $plan = Plan::factory()->create();
        $addOn = $this->planModule($plan, ['is_addon' => true, 'enabled' => false]);

        $test = Livewire::test(ViewPlanAddOn::class, ['record' => $addOn->uuid]);
        $test->mountAction(SetPlanModuleEnabledAction::getDefaultName());
        $test->callMountedAction();

        $test->assertHasNoActionErrors();

        $addOn->refresh();
        $this->assertTrue($addOn->enabled);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'plan_module_enabled')
                ->where('actor_id', $actor->id)
                ->first()
        );
        $this->assertNotNull($row);
    }

    public function test_enable_disable_action_is_denied_for_a_billing_admin(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::BillingAdmin);
        $this->actingAs($actor, 'platform_admin');

        $plan = Plan::factory()->create();
        $addOn = $this->planModule($plan, ['is_addon' => true, 'enabled' => true]);

        $test = Livewire::test(ViewPlanAddOn::class, ['record' => $addOn->uuid]);
        $test->mountAction(SetPlanModuleEnabledAction::getDefaultName());
        $test->callMountedAction();

        $addOn->refresh();
        $this->assertTrue($addOn->enabled, 'A BillingAdmin must not be able to toggle an add-on.');
    }

    // --- Retire action lifecycle ---

    public function test_retire_action_retires_an_add_on_and_writes_an_audit_event(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $plan = Plan::factory()->create();
        $addOn = $this->planModule($plan, ['is_addon' => true, 'enabled' => true, 'status' => PlanModuleStatus::Active]);

        $test = Livewire::test(ViewPlanAddOn::class, ['record' => $addOn->uuid]);
        $test->mountAction(RetirePlanModuleAction::getDefaultName());
        $test->callMountedAction();

        $test->assertHasNoActionErrors();

        $addOn->refresh();
        $this->assertSame(PlanModuleStatus::Retired, $addOn->status);
        $this->assertFalse($addOn->enabled);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'plan_module_retired')
                ->where('actor_id', $actor->id)
                ->first()
        );
        $this->assertNotNull($row);
    }

    public function test_neither_action_is_visible_for_an_already_retired_add_on(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $plan = Plan::factory()->create();
        $addOn = $this->planModule($plan, ['is_addon' => true, 'status' => PlanModuleStatus::Retired, 'enabled' => false]);

        $test = Livewire::test(ViewPlanAddOn::class, ['record' => $addOn->uuid]);
        $test->assertActionHidden(SetPlanModuleEnabledAction::getDefaultName());
        $test->assertActionHidden(RetirePlanModuleAction::getDefaultName());
    }
}
