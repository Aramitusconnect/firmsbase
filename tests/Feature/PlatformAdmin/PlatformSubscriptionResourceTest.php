<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Enums\PlatformSubscriptionStatus;
use App\Filament\Actions\Platform\CancelSubscriptionAction;
use App\Filament\Resources\PlatformSubscriptionResource;
use App\Filament\Resources\PlatformSubscriptionResource\Pages\ListPlatformSubscriptions;
use App\Filament\Resources\PlatformSubscriptionResource\Pages\ViewPlatformSubscription;
use App\Models\BillingAccount;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\PlatformSubscription;
use App\Services\PlatformRoleService;
use App\Services\TenantContextService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlatformSubscriptionResourceTest — Phase 3 (FirmsVault Platform Admin
 * Control Center, "Billing and Commercial Administration"). Navigation
 * visibility, route-level authorization, filters, deterministic
 * ordering, bounded pagination, no-N+1, and the Cancel action's full
 * lifecycle (authorization allow/deny, audit event, resulting state).
 */
final class PlatformSubscriptionResourceTest extends TestCase
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
        $this->assertFalse(PlatformSubscriptionResource::canAccess());
    }

    public function test_navigation_is_hidden_for_a_platform_admin_with_no_role(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'platform_admin');

        $this->assertFalse(PlatformSubscriptionResource::canAccess());
    }

    public function test_navigation_is_hidden_for_a_role_outside_platform_billing(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin');

        $this->assertFalse(PlatformSubscriptionResource::canAccess());
    }

    public function test_navigation_is_visible_for_a_billing_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::BillingAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->assertTrue(PlatformSubscriptionResource::canAccess());
    }

    // --- Route-level authorization ---

    public function test_guest_is_redirected_from_the_subscriptions_list(): void
    {
        $this->get(PlatformSubscriptionResource::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'platform_admin')->get(PlatformSubscriptionResource::getUrl())->assertForbidden();
    }

    public function test_a_sales_rep_is_forbidden(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin')->get(PlatformSubscriptionResource::getUrl())->assertForbidden();
    }

    public function test_a_billing_admin_can_reach_the_list_and_view_a_record(): void
    {
        $account = BillingAccount::factory()->create(['name' => 'Acme Billing']);
        $subscription = PlatformSubscription::factory()->forBillingAccount($account)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::BillingAdmin);

        $listResponse = $this->actingAs($admin, 'platform_admin')->get(PlatformSubscriptionResource::getUrl());
        $listResponse->assertOk();
        $listResponse->assertSee('Acme Billing');

        $viewResponse = $this->actingAs($admin, 'platform_admin')
            ->get(PlatformSubscriptionResource::getUrl('view', ['record' => $subscription]));
        $viewResponse->assertOk();
        $viewResponse->assertSee('Acme Billing');
    }

    // --- Empty state ---

    public function test_the_list_page_shows_an_empty_state_with_no_subscriptions(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(PlatformSubscriptionResource::getUrl());
        $response->assertOk();
        $response->assertSee('No subscriptions found');
    }

    // --- Filters ---

    public function test_status_filter_narrows_the_list(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $account = BillingAccount::factory()->create();
        $active = PlatformSubscription::factory()->forBillingAccount($account)->create(['status' => PlatformSubscriptionStatus::Active]);
        $cancelled = PlatformSubscription::factory()->forBillingAccount($account)->create(['status' => PlatformSubscriptionStatus::Cancelled, 'cancelled_at' => now()]);

        $test = Livewire::test(ListPlatformSubscriptions::class);
        $test->filterTable('status', PlatformSubscriptionStatus::Active->value);

        $test->assertCanSeeTableRecords([$active]);
        $test->assertCanNotSeeTableRecords([$cancelled]);
    }

    public function test_plan_filter_narrows_the_list(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $planA = Plan::factory()->create(['name' => 'Plan A']);
        $planB = Plan::factory()->create(['name' => 'Plan B']);
        $account = BillingAccount::factory()->create();
        $subA = PlatformSubscription::factory()->forBillingAccount($account)->create(['plan_id' => $planA->id]);
        $subB = PlatformSubscription::factory()->forBillingAccount($account)->create(['plan_id' => $planB->id]);

        $test = Livewire::test(ListPlatformSubscriptions::class);
        $test->filterTable('plan_id', $planA->id);

        $test->assertCanSeeTableRecords([$subA]);
        $test->assertCanNotSeeTableRecords([$subB]);
    }

    // --- Deterministic ordering ---

    public function test_orders_deterministically_when_period_start_ties(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $account = BillingAccount::factory()->create();
        $tied = now()->startOfMonth();

        $subscriptions = PlatformSubscription::factory()
            ->forBillingAccount($account)
            ->count(5)
            ->create(['current_period_starts_at' => $tied]);

        $test = Livewire::test(ListPlatformSubscriptions::class);
        $firstOrder = $test->instance()->getFilteredSortedTableQuery()->pluck('id')->all();

        $test2 = Livewire::test(ListPlatformSubscriptions::class);
        $secondOrder = $test2->instance()->getFilteredSortedTableQuery()->pluck('id')->all();

        $this->assertSame($firstOrder, $secondOrder, 'Tied current_period_starts_at rows must order identically across repeated calls.');
        $this->assertSame($subscriptions->sortBy('id')->pluck('id')->values()->all(), collect($firstOrder)->sort()->values()->all());
    }

    // --- Bounded pagination ---

    public function test_the_list_is_paginated(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $account = BillingAccount::factory()->create();
        PlatformSubscription::factory()->forBillingAccount($account)->count(30)->create();

        $test = Livewire::test(ListPlatformSubscriptions::class);
        $test->assertSuccessful();

        $this->assertLessThanOrEqual(25, $test->instance()->getTableRecords()->count());
    }

    // --- No N+1 ---

    public function test_the_list_page_does_not_n_plus_one_on_billing_account_or_plan(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        foreach (range(1, 8) as $i) {
            PlatformSubscription::factory()->create();
        }

        $captured = [];
        DB::listen(function ($query) use (&$captured): void {
            $captured[] = $query->sql;
        });

        Livewire::test(ListPlatformSubscriptions::class)->assertSuccessful();

        $billingAccountQueries = collect($captured)->filter(fn (string $sql): bool => str_contains($sql, 'billing_accounts'))->count();
        $planQueries = collect($captured)->filter(fn (string $sql): bool => str_contains($sql, '"plans"') || str_contains($sql, '`plans`'))->count();

        $this->assertLessThanOrEqual(1, $billingAccountQueries, 'Expected at most one batched billing_accounts query, never one per row.');
        // At most 2, not 1: one for the eager-loaded plan relation, one
        // for the "Plan" SelectFilter's own options() list — both are
        // fixed-cost (independent of row count), never one per row, so
        // this is still a genuine no-N+1 proof, just with a slightly
        // higher fixed baseline than billing_accounts (which has no
        // corresponding filter dropdown).
        $this->assertLessThanOrEqual(2, $planQueries, 'Expected at most two fixed-cost plans queries (eager load + filter options), never one per row.');
    }

    // --- Cancel action lifecycle ---

    public function test_cancel_at_period_end_sets_the_flag_without_changing_status(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $account = BillingAccount::factory()->create();
        $subscription = PlatformSubscription::factory()->forBillingAccount($account)->create(['status' => PlatformSubscriptionStatus::Active]);

        $test = Livewire::test(ViewPlatformSubscription::class, ['record' => $subscription->uuid]);
        $test->mountAction(CancelSubscriptionAction::getDefaultName());
        $test->setActionData(['at_period_end' => 1]);
        $test->callMountedAction();

        $test->assertHasNoActionErrors();

        $subscription->refresh();
        $this->assertTrue($subscription->cancel_at_period_end);
        $this->assertSame(PlatformSubscriptionStatus::Active, $subscription->status);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->where('event_type', 'subscription_cancelled')
                ->where('actor_id', $actor->id)
                ->first()
        );
        $this->assertNotNull($row, 'A security_events audit row must be written for the Cancel action.');
    }

    public function test_cancel_immediately_sets_cancelled_status_and_timestamp(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $account = BillingAccount::factory()->create();
        $subscription = PlatformSubscription::factory()->forBillingAccount($account)->create(['status' => PlatformSubscriptionStatus::Active]);

        $test = Livewire::test(ViewPlatformSubscription::class, ['record' => $subscription->uuid]);
        $test->mountAction(CancelSubscriptionAction::getDefaultName());
        // 0, not PHP false: Radio::boolean() stores its "false" option
        // under the literal key 0 (see CancelSubscriptionAction's own
        // schema) — Livewire's own validation compares the submitted
        // value against that option key, and a raw PHP `false` fails
        // that comparison (stringifies to '', not '0').
        $test->setActionData(['at_period_end' => 0]);
        $test->callMountedAction();

        $test->assertHasNoActionErrors();

        $subscription->refresh();
        $this->assertSame(PlatformSubscriptionStatus::Cancelled, $subscription->status);
        $this->assertNotNull($subscription->cancelled_at);
    }

    public function test_cancel_action_is_denied_for_a_billing_admin_because_manage_requires_a_narrower_role(): void
    {
        // canManagePlatformBilling() is narrowed to SuperAdmin/PlatformAdmin
        // only — BillingAdmin passes canAccessPlatformBilling() (read) but
        // must be denied here.
        $actor = $this->adminWithRole(PlatformRoleCode::BillingAdmin);
        $this->actingAs($actor, 'platform_admin');

        $account = BillingAccount::factory()->create();
        $subscription = PlatformSubscription::factory()->forBillingAccount($account)->create(['status' => PlatformSubscriptionStatus::Active]);

        $test = Livewire::test(ViewPlatformSubscription::class, ['record' => $subscription->uuid]);
        $test->mountAction(CancelSubscriptionAction::getDefaultName());
        $test->setActionData(['at_period_end' => true]);
        $test->callMountedAction();

        $subscription->refresh();
        $this->assertFalse($subscription->cancel_at_period_end, 'A BillingAdmin must not be able to cancel a subscription.');
        $this->assertSame(PlatformSubscriptionStatus::Active, $subscription->status);
    }

    public function test_cancel_action_is_not_visible_for_an_already_cancelled_subscription(): void
    {
        $actor = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($actor, 'platform_admin');

        $account = BillingAccount::factory()->create();
        $subscription = PlatformSubscription::factory()->forBillingAccount($account)->create([
            'status' => PlatformSubscriptionStatus::Cancelled,
            'cancelled_at' => now(),
        ]);

        $test = Livewire::test(ViewPlatformSubscription::class, ['record' => $subscription->uuid]);
        $test->assertActionHidden(CancelSubscriptionAction::getDefaultName());
    }
}
