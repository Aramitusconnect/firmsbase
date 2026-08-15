<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\BillingInterval;
use App\Enums\PlanLimitMetric;
use App\Enums\PlanStatus;
use App\Enums\PlatformRoleCode;
use App\Enums\PlatformSubscriptionStatus;
use App\Filament\Resources\PlanAddOnResource;
use App\Filament\Resources\PlanResource;
use App\Models\BillingAccount;
use App\Models\ModuleCatalog;
use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\PlanModule;
use App\Models\PlatformAdmin;
use App\Models\PlatformSubscription;
use App\Services\PlanService;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * PlanCatalogGovernanceTest — Billing & Commercial Control Plane pass.
 *
 * The plan catalog is commercial source configuration: what a plan is
 * set up to grant, and what it costs. The risk here is not a missing
 * feature, it is a plan change that silently reprices existing
 * subscribers or an operator making one without knowing who is on the
 * plan. These tests hold both in place.
 */
final class PlanCatalogGovernanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function billingAdmin(): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::BillingAdmin);

        return $admin;
    }

    private function moduleInCatalog(string $code, string $name): ModuleCatalog
    {
        return ModuleCatalog::query()->create([
            'module_code' => $code,
            'module_name' => $name,
            'category' => 'practice',
            'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------------
    // Plan detail: composition and reach
    // ------------------------------------------------------------------

    public function test_the_plan_detail_page_shows_who_is_on_the_plan(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        $plan = Plan::factory()->create([
            'name' => 'Practice Pro',
            'price_cents' => 29900,
            'billing_interval' => BillingInterval::Monthly,
            'status' => PlanStatus::Active,
        ]);

        PlatformSubscription::factory()->count(3)->create([
            'billing_account_id' => BillingAccount::factory()->create()->id,
            'plan_id' => $plan->id,
            'status' => PlatformSubscriptionStatus::Active,
        ]);
        PlatformSubscription::factory()->create([
            'billing_account_id' => BillingAccount::factory()->create()->id,
            'plan_id' => $plan->id,
            'status' => PlatformSubscriptionStatus::Cancelled,
        ]);

        $response = $this->get(PlanResource::getUrl('view', ['record' => $plan]));
        $response->assertOk();
        $response->assertSee('Who is on this plan');
        $response->assertSee('Platform subscriptions');
        $response->assertSee('3');
        $response->assertSee('299.00 USD');
    }

    public function test_the_plan_detail_page_lists_bundled_modules_and_add_ons_by_display_name(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        $plan = Plan::factory()->create(['name' => 'Practice Pro']);
        $this->moduleInCatalog('matter_analytics', 'Matter Analytics');
        $this->moduleInCatalog('core_matters', 'Core Matter Management');

        PlanModule::query()->create([
            'plan_id' => $plan->id,
            'module_code' => 'core_matters',
            'enabled' => true,
            'is_addon' => false,
        ]);
        PlanModule::query()->create([
            'plan_id' => $plan->id,
            'module_code' => 'matter_analytics',
            'enabled' => true,
            'is_addon' => true,
        ]);

        $response = $this->get(PlanResource::getUrl('view', ['record' => $plan]));
        $response->assertOk();
        $response->assertSee('Bundled modules');
        $response->assertSee('Core Matter Management');
        $response->assertSee('Optional add-ons');
        $response->assertSee('Matter Analytics');
    }

    public function test_the_plan_detail_page_shows_limits_and_says_so_when_there_are_none(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        $limited = Plan::factory()->create();
        PlanLimit::query()->create([
            'plan_id' => $limited->id,
            'metric' => PlanLimitMetric::SeatsAttorney,
            'limit_value' => 25,
        ]);

        $this->get(PlanResource::getUrl('view', ['record' => $limited]))
            ->assertOk()
            ->assertSee('Seats Attorney')
            ->assertSee('25');

        $unlimited = Plan::factory()->create();

        $this->get(PlanResource::getUrl('view', ['record' => $unlimited]))
            ->assertOk()
            ->assertSee('none configured');
    }

    // ------------------------------------------------------------------
    // Versioning truth
    // ------------------------------------------------------------------

    public function test_the_plan_detail_page_states_that_there_is_no_versioning_or_grandfathering(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        $plan = Plan::factory()->create();

        $response = $this->get(PlanResource::getUrl('view', ['record' => $plan]));
        $response->assertOk();
        $response->assertSee('Plans have no versions and no effective dates');
        $response->assertSee('no grandfathering');
        $response->assertSee('no proration');
    }

    public function test_the_plan_detail_page_says_the_terms_are_still_editable_when_nothing_references_the_plan(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        $plan = Plan::factory()->create();

        $response = $this->get(PlanResource::getUrl('view', ['record' => $plan]));
        $response->assertOk();
        $response->assertSee('Nothing references this plan yet');
    }

    public function test_the_plan_detail_page_says_the_terms_are_locked_once_the_plan_is_in_use(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        $plan = Plan::factory()->create();
        PlatformSubscription::factory()->create([
            'billing_account_id' => BillingAccount::factory()->create()->id,
            'plan_id' => $plan->id,
            'status' => PlatformSubscriptionStatus::Active,
        ]);

        $response = $this->get(PlanResource::getUrl('view', ['record' => $plan]));
        $response->assertOk();
        $response->assertSee('This plan is in use');
        $response->assertSee('are locked and cannot be');
    }

    public function test_the_plan_detail_page_states_that_tax_discounts_and_setup_fees_do_not_exist(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        $plan = Plan::factory()->create();

        $response = $this->get(PlanResource::getUrl('view', ['record' => $plan]));
        $response->assertOk();
        $response->assertSee('no tax rate, tax behaviour, jurisdiction');
        $response->assertSee('no setup fee, no discount, coupon, or');
        $response->assertSee('These are absent capabilities, not empty settings');
    }

    // ------------------------------------------------------------------
    // The invariant behind the disclosure: no silent repricing
    // ------------------------------------------------------------------

    public function test_a_plan_in_use_cannot_have_its_price_changed(): void
    {
        $plan = Plan::factory()->create([
            'price_cents' => 29900,
            'billing_interval' => BillingInterval::Monthly,
        ]);
        PlatformSubscription::factory()->create([
            'billing_account_id' => BillingAccount::factory()->create()->id,
            'plan_id' => $plan->id,
            'status' => PlatformSubscriptionStatus::Active,
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(PlanService::class)->update($plan, ['price_cents' => 9900]);
    }

    public function test_a_rejected_price_change_leaves_the_stored_price_untouched(): void
    {
        $plan = Plan::factory()->create([
            'price_cents' => 29900,
            'billing_interval' => BillingInterval::Monthly,
        ]);
        PlatformSubscription::factory()->create([
            'billing_account_id' => BillingAccount::factory()->create()->id,
            'plan_id' => $plan->id,
            'status' => PlatformSubscriptionStatus::Active,
        ]);

        try {
            app(PlanService::class)->update($plan, ['price_cents' => 9900]);
        } catch (InvalidArgumentException) {
            // Expected — asserted by the test above.
        }

        $this->assertSame(29900, (int) DB::table('plans')->where('id', $plan->id)->value('price_cents'));
    }

    public function test_descriptive_fields_stay_editable_on_a_plan_that_is_in_use(): void
    {
        $plan = Plan::factory()->create([
            'name' => 'Practice Pro',
            'price_cents' => 29900,
            'billing_interval' => BillingInterval::Monthly,
        ]);
        PlatformSubscription::factory()->create([
            'billing_account_id' => BillingAccount::factory()->create()->id,
            'plan_id' => $plan->id,
            'status' => PlatformSubscriptionStatus::Active,
        ]);

        $updated = app(PlanService::class)->update($plan, ['name' => 'Practice Pro (2026)']);

        $this->assertSame('Practice Pro (2026)', $updated->name);
        $this->assertSame(29900, (int) $updated->price_cents);
    }

    public function test_the_plan_resource_exposes_no_delete_route(): void
    {
        $this->assertSame(['index', 'view'], array_keys(PlanResource::getPages()));
    }

    // ------------------------------------------------------------------
    // Add-on catalog truth
    // ------------------------------------------------------------------

    public function test_the_add_on_list_leads_with_the_module_display_name_not_the_raw_code(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        $plan = Plan::factory()->create();
        $this->moduleInCatalog('matter_analytics', 'Matter Analytics');
        PlanModule::query()->create([
            'plan_id' => $plan->id,
            'module_code' => 'matter_analytics',
            'enabled' => true,
            'is_addon' => true,
        ]);

        $response = $this->get(PlanAddOnResource::getUrl());
        $response->assertOk();
        $response->assertSee('Matter Analytics');
        $response->assertSee('Add-on');
    }

    public function test_the_add_on_list_states_that_add_ons_carry_no_price_of_their_own(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        $response = $this->get(PlanAddOnResource::getUrl());
        $response->assertOk();
        $response->assertSee('carries no price of its own');
    }

    public function test_the_add_on_list_states_that_no_dependency_model_exists(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        $response = $this->get(PlanAddOnResource::getUrl());
        $response->assertOk();
        $response->assertSee('models no dependencies between modules');
        $response->assertSee('conflicts-with, included-with, or replaces');
    }

    public function test_the_add_on_list_states_that_changes_cannot_be_scheduled(): void
    {
        $this->actingAs($this->billingAdmin(), 'platform_admin');

        $response = $this->get(PlanAddOnResource::getUrl());
        $response->assertOk();
        $response->assertSee('no change here can be scheduled for a future date');
    }

    /**
     * Guard against a future pass inventing UI-only dependency rules.
     * The backend expresses no dependency relation of any kind, so a
     * console-side one would be enforced here and nowhere else.
     */
    public function test_no_dependency_or_conflict_rule_is_implemented_in_the_add_on_console(): void
    {
        foreach ([
            app_path('Filament/Resources/PlanAddOnResource.php'),
            app_path('Filament/Actions/Platform/AddPlanModuleAction.php'),
            app_path('Filament/Actions/Platform/SetPlanModuleEnabledAction.php'),
            app_path('Filament/Actions/Platform/RetirePlanModuleAction.php'),
        ] as $file) {
            $source = file_get_contents($file);

            foreach (['requires_module', 'conflicts_with', 'included_with', 'replaces_module'] as $invented) {
                $this->assertStringNotContainsString($invented, $source);
            }
        }
    }
}
