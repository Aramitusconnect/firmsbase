<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlanStatus;
use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\ActivatePlanAction;
use App\Filament\Actions\Platform\ArchivePlanAction;
use App\Filament\Resources\PlanResource;
use App\Filament\Resources\PlanResource\Pages\ListPlans;
use App\Filament\Resources\PlanResource\Pages\ViewPlan;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use App\Services\TenantContextService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlanResourceTest — Phase 3 (FirmsVault Platform Admin Control Center,
 * "Billing and Commercial Administration"). Navigation visibility,
 * route-level authorization, filters, deterministic ordering, bounded
 * pagination, MoneyDisplay rendering (this Resource is the required
 * spot-check per this pass's own testing instructions), and the
 * Activate/Archive actions' full lifecycle.
 */
final class PlanResourceTest extends TestCase
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

    // --- Navigation visibility ---

    public function test_navigation_is_hidden_when_no_admin_is_authenticated(): void
    {
        $this->assertFalse(PlanResource::canAccess());
    }

    public function test_navigation_is_hidden_for_a_platform_admin_with_no_role(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'platform_admin');

        $this->assertFalse(PlanResource::canAccess());
    }

    public function test_navigation_is_visible_for_a_billing_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::BillingAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->assertTrue(PlanResource::canAccess());
    }

    // --- Route-level authorization ---

    public function test_guest_is_redirected_from_the_plans_list(): void
    {
        $this->get(PlanResource::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_sales_rep_is_forbidden(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin')->get(PlanResource::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_list_and_view_a_record(): void
    {
        $plan = Plan::factory()->create(['name' => 'Growth Plan']);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $listResponse = $this->actingAs($admin, 'platform_admin')->get(PlanResource::getUrl());
        $listResponse->assertOk();
        $listResponse->assertSee('Growth Plan');

        $viewResponse = $this->actingAs($admin, 'platform_admin')
            ->get(PlanResource::getUrl('view', ['record' => $plan]));
        $viewResponse->assertOk();
        $viewResponse->assertSee('Growth Plan');
    }

    // --- MoneyDisplay spot-check ---

    public function test_price_column_renders_via_money_display_not_a_raw_integer(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        Plan::factory()->create(['name' => 'Priced Plan', 'price_cents' => 24900]);

        $response = $this->get(PlanResource::getUrl());
        $response->assertOk();
        // $249.00 — MoneyDisplay::fromCents(24900), never the raw
        // integer 24900 alone.
        $response->assertSee('249.00');
        $response->assertDontSee('24900');
    }

    // --- Empty state ---

    public function test_the_list_page_shows_an_empty_state_with_no_plans(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(PlanResource::getUrl());
        $response->assertOk();
        $response->assertSee('No plans found');
    }

    // --- Filters ---

    public function test_status_filter_narrows_the_list(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $active = Plan::factory()->create(['status' => PlanStatus::Active]);
        $draft = Plan::factory()->draft()->create();

        $test = Livewire::test(ListPlans::class);
        $test->filterTable('status', PlanStatus::Active->value);

        $test->assertCanSeeTableRecords([$active]);
        $test->assertCanNotSeeTableRecords([$draft]);
    }

    // --- Deterministic ordering ---

    public function test_orders_deterministically_when_name_ties(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $plans = Plan::factory()->count(5)->create(['name' => 'Tied Plan Name']);

        $first = Livewire::test(ListPlans::class)->instance()->getFilteredSortedTableQuery()->pluck('id')->all();
        $second = Livewire::test(ListPlans::class)->instance()->getFilteredSortedTableQuery()->pluck('id')->all();

        $this->assertSame($first, $second, 'Tied name rows must order identically across repeated calls.');
        $this->assertSame($plans->sortBy('id')->pluck('id')->values()->all(), $first);
    }

    // --- Bounded pagination ---

    public function test_the_list_is_paginated(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        Plan::factory()->count(30)->create();

        $test = Livewire::test(ListPlans::class);
        $test->assertSuccessful();

        $this->assertLessThanOrEqual(25, $test->instance()->getTableRecords()->count());
    }

    // --- Activate action lifecycle ---

    public function test_activate_action_moves_a_draft_plan_to_active_and_writes_an_audit_event(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $plan = Plan::factory()->draft()->create();

        $test = Livewire::test(ViewPlan::class, ['record' => $plan->uuid]);
        $test->mountAction(ActivatePlanAction::getDefaultName());
        $test->callMountedAction();

        $test->assertHasNoActionErrors();

        $plan->refresh();
        $this->assertSame(PlanStatus::Active, $plan->status);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'plan_activated')
                ->where('actor_id', $actor->id)
                ->first()
        );
        $this->assertNotNull($row, 'A security_events audit row must be written for the Activate action.');
    }

    public function test_activate_action_is_denied_for_a_billing_admin(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::BillingAdmin);
        $this->actingAs($actor, 'platform_admin');

        $plan = Plan::factory()->draft()->create();

        $test = Livewire::test(ViewPlan::class, ['record' => $plan->uuid]);
        $test->mountAction(ActivatePlanAction::getDefaultName());
        $test->callMountedAction();

        $plan->refresh();
        $this->assertSame(PlanStatus::Draft, $plan->status, 'A BillingAdmin must not be able to activate a plan.');
    }

    public function test_activate_action_is_not_visible_for_an_already_active_plan(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $plan = Plan::factory()->create(['status' => PlanStatus::Active]);

        $test = Livewire::test(ViewPlan::class, ['record' => $plan->uuid]);
        $test->assertActionHidden(ActivatePlanAction::getDefaultName());
    }

    // --- Archive action lifecycle ---

    public function test_archive_action_moves_an_active_plan_to_archived_and_writes_an_audit_event(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::PlatformAdmin);
        $this->actingAs($actor, 'platform_admin');

        $plan = Plan::factory()->create(['status' => PlanStatus::Active, 'is_active' => true]);

        $test = Livewire::test(ViewPlan::class, ['record' => $plan->uuid]);
        $test->mountAction(ArchivePlanAction::getDefaultName());
        $test->callMountedAction();

        $test->assertHasNoActionErrors();

        $plan->refresh();
        $this->assertSame(PlanStatus::Archived, $plan->status);
        $this->assertFalse($plan->is_active);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'plan_archived')
                ->where('actor_id', $actor->id)
                ->first()
        );
        $this->assertNotNull($row, 'A security_events audit row must be written for the Archive action.');
    }

    public function test_archive_action_is_denied_for_a_read_only_auditor_even_with_super_admin(): void
    {
        // Blanket rule 9: read_only_auditor may never mutate data,
        // regardless of any other role also held (canMutate()).
        $actor = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($actor, PlatformRoleCode::SuperAdmin);
        app(PlatformRoleService::class)->grant($actor, PlatformRoleCode::ReadOnlyAuditor);
        $this->actingAs($actor, 'platform_admin');

        $plan = Plan::factory()->create(['status' => PlanStatus::Active]);

        $test = Livewire::test(ViewPlan::class, ['record' => $plan->uuid]);
        $test->mountAction(ArchivePlanAction::getDefaultName());
        $test->callMountedAction();

        $plan->refresh();
        $this->assertSame(PlanStatus::Active, $plan->status, 'A read_only_auditor must never be able to mutate a plan, regardless of also holding SuperAdmin.');
    }
}
