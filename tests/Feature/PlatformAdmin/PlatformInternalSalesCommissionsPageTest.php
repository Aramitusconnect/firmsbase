<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\CommissionEventStatus;
use App\Enums\CommissionEventType;
use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformInternalSalesCommissionsPage;
use App\Models\BillingAccount;
use App\Models\CommissionEvent;
use App\Models\CommissionPlan;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlatformInternalSalesCommissionsPageTest — Billing & Commercial
 * Control Plane pass. Covers the commission data that used to live
 * inside PlatformResellersPage: navigation visibility, direct-route
 * authorization, honest naming (internal employee commission, NOT
 * reseller/partner commission), MoneyDisplay rendering, filters,
 * deterministic ordering, bounded pagination, empty state, and a
 * positive proof that no mutating action is ever registered.
 */
final class PlatformInternalSalesCommissionsPageTest extends TestCase
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

    public function test_navigation_is_hidden_for_a_guest(): void
    {
        $this->assertFalse(PlatformInternalSalesCommissionsPage::shouldRegisterNavigation());
    }

    public function test_navigation_is_visible_for_a_billing_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::BillingAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->assertTrue(PlatformInternalSalesCommissionsPage::shouldRegisterNavigation());
    }

    public function test_the_navigation_label_names_the_domain_honestly(): void
    {
        $this->assertSame(
            'Internal Sales Commissions',
            PlatformInternalSalesCommissionsPage::getNavigationLabel(),
        );
    }

    // --- Direct-route authorization ---

    public function test_guest_is_redirected(): void
    {
        $this->get(PlatformInternalSalesCommissionsPage::getUrl())->assertRedirect($this->adminUrl('/login'));
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'platform_admin')
            ->get(PlatformInternalSalesCommissionsPage::getUrl())
            ->assertForbidden();
    }

    public function test_a_sales_rep_is_forbidden(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);

        $this->actingAs($admin, 'platform_admin')
            ->get(PlatformInternalSalesCommissionsPage::getUrl())
            ->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_page(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->actingAs($admin, 'platform_admin')
            ->get(PlatformInternalSalesCommissionsPage::getUrl())
            ->assertOk();
    }

    // --- Honest naming: internal employee commission, not reseller ---

    public function test_the_page_states_this_is_not_reseller_commission(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')
            ->get(PlatformInternalSalesCommissionsPage::getUrl());

        $response->assertOk();
        $response->assertSee('This is not reseller or partner commission');
        $response->assertSee('no reseller/partner account domain exists');
    }

    public function test_the_page_states_commission_is_never_billed_to_a_customer(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')
            ->get(PlatformInternalSalesCommissionsPage::getUrl());

        $response->assertOk();
        $response->assertSee('never billed to a customer');
    }

    // --- Real data + MoneyDisplay spot-check ---

    public function test_real_commission_event_data_renders_correctly_via_money_display(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $plan = CommissionPlan::factory()->create(['name' => 'Enterprise Referral Plan']);
        $account = BillingAccount::factory()->create();
        CommissionEvent::factory()->create([
            'commission_plan_id' => $plan->id,
            'billing_account_id' => $account->id,
            'amount_cents' => 45000,
        ]);

        $response = $this->get(PlatformInternalSalesCommissionsPage::getUrl());
        $response->assertOk();
        $response->assertSee('Enterprise Referral Plan');
        $response->assertSee('450.00 USD');
        $response->assertDontSee('45000');
    }

    public function test_the_attributed_sales_rep_is_shown(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $rep = PlatformAdmin::factory()->create(['name' => 'Dana Sellsworth']);
        CommissionEvent::factory()->create(['platform_admin_id' => $rep->id]);

        $response = $this->get(PlatformInternalSalesCommissionsPage::getUrl());
        $response->assertOk();
        $response->assertSee('Dana Sellsworth');
    }

    // --- Empty state ---

    public function test_an_honest_empty_state_is_shown_when_no_commission_events_exist(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')
            ->get(PlatformInternalSalesCommissionsPage::getUrl());

        $response->assertOk();
        $response->assertSee('No internal sales commission events recorded');
    }

    // --- Filters ---

    public function test_status_filter_narrows_the_list(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $payable = CommissionEvent::factory()->create(['status' => CommissionEventStatus::Payable]);
        $pending = CommissionEvent::factory()->create(['status' => CommissionEventStatus::Pending]);

        $test = Livewire::test(PlatformInternalSalesCommissionsPage::class);
        $test->filterTable('status', CommissionEventStatus::Payable->value);

        $test->assertCanSeeTableRecords([$payable]);
        $test->assertCanNotSeeTableRecords([$pending]);
    }

    public function test_event_type_filter_narrows_the_list(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $newBusiness = CommissionEvent::factory()->create(['event_type' => CommissionEventType::NewBusiness]);
        $renewal = CommissionEvent::factory()->create(['event_type' => CommissionEventType::Renewal]);

        $test = Livewire::test(PlatformInternalSalesCommissionsPage::class);
        $test->filterTable('event_type', CommissionEventType::Renewal->value);

        $test->assertCanSeeTableRecords([$renewal]);
        $test->assertCanNotSeeTableRecords([$newBusiness]);
    }

    public function test_sales_rep_filter_narrows_the_list(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $rep = PlatformAdmin::factory()->create(['name' => 'Dana Sellsworth']);
        $mine = CommissionEvent::factory()->create(['platform_admin_id' => $rep->id]);
        $theirs = CommissionEvent::factory()->create(['platform_admin_id' => null]);

        $test = Livewire::test(PlatformInternalSalesCommissionsPage::class);
        $test->filterTable('platform_admin_id', (string) $rep->id);

        $test->assertCanSeeTableRecords([$mine]);
        $test->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_commission_plan_filter_narrows_the_list(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $planA = CommissionPlan::factory()->create(['name' => 'Plan A']);
        $planB = CommissionPlan::factory()->create(['name' => 'Plan B']);
        $onA = CommissionEvent::factory()->create(['commission_plan_id' => $planA->id]);
        $onB = CommissionEvent::factory()->create(['commission_plan_id' => $planB->id]);

        $test = Livewire::test(PlatformInternalSalesCommissionsPage::class);
        $test->filterTable('commission_plan_id', (string) $planA->id);

        $test->assertCanSeeTableRecords([$onA]);
        $test->assertCanNotSeeTableRecords([$onB]);
    }

    // --- Deterministic ordering ---

    public function test_orders_deterministically_by_id_even_when_amounts_tie(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $events = CommissionEvent::factory()->count(5)->create(['amount_cents' => 5000]);

        $first = Livewire::test(PlatformInternalSalesCommissionsPage::class)
            ->instance()->getFilteredSortedTableQuery()->pluck('id')->all();
        $second = Livewire::test(PlatformInternalSalesCommissionsPage::class)
            ->instance()->getFilteredSortedTableQuery()->pluck('id')->all();

        $this->assertSame($first, $second, 'Tied amount rows must order identically across repeated calls.');
        $this->assertSame($events->sortByDesc('id')->pluck('id')->values()->all(), $first);
    }

    // --- Bounded pagination ---

    public function test_the_page_is_paginated(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        CommissionEvent::factory()->count(30)->create();

        $test = Livewire::test(PlatformInternalSalesCommissionsPage::class);
        $test->assertSuccessful();

        $this->assertLessThanOrEqual(25, $test->instance()->getTableRecords()->count());
    }

    // --- Positive proof: read-only ---

    public function test_no_filament_action_is_registered_and_no_commission_mutation_method_is_ever_called(): void
    {
        $source = file_get_contents(app_path('Filament/Pages/PlatformInternalSalesCommissionsPage.php'));

        $this->assertStringNotContainsString('Action::make(', $source);
        $this->assertStringNotContainsString('->action(', $source);
        $this->assertStringNotContainsString('->markPaid(', $source);
        $this->assertStringNotContainsString('->reverse(', $source);
        $this->assertStringNotContainsString('use App\Services\CommissionEventService;', $source);
    }
}
