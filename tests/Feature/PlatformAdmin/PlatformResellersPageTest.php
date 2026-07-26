<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\CommissionEventStatus;
use App\Enums\CommissionEventType;
use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformResellersPage;
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
 * PlatformResellersPageTest — Phase 3 (FirmsVault Platform Admin
 * Control Center, "Billing and Commercial Administration"). Navigation
 * visibility, direct-route authorization, the honest "no reseller
 * system exists" disclosure, the separately-labeled internal sales
 * commission data section, MoneyDisplay rendering, filters,
 * deterministic ordering, bounded pagination, empty state, and a
 * positive proof that no mutating action (markPaid/reverse) is ever
 * registered or called here.
 */
final class PlatformResellersPageTest extends TestCase
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
        $this->assertFalse(PlatformResellersPage::shouldRegisterNavigation());
    }

    public function test_navigation_is_visible_for_a_billing_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::BillingAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->assertTrue(PlatformResellersPage::shouldRegisterNavigation());
    }

    // --- Direct-route authorization ---

    public function test_guest_is_redirected_from_the_resellers_page(): void
    {
        $this->get(PlatformResellersPage::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'platform_admin')->get(PlatformResellersPage::getUrl())->assertForbidden();
    }

    public function test_a_sales_rep_is_forbidden(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin')->get(PlatformResellersPage::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_page(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformResellersPage::getUrl());
        $response->assertOk();
    }

    // --- Honest disclosure ---

    public function test_the_page_honestly_discloses_no_reseller_partner_system_exists(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformResellersPage::getUrl());
        $response->assertOk();
        $response->assertSee('No Reseller/Partner Account System Exists');
        $response->assertSee('exhaustive repository');
    }

    public function test_the_commission_section_is_separately_and_honestly_labeled(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformResellersPage::getUrl());
        $response->assertOk();
        $response->assertSee('Internal Sales Commission Data (not a reseller/partner system)');
    }

    // --- Real commission data rendering + MoneyDisplay spot-check ---

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

        $response = $this->get(PlatformResellersPage::getUrl());
        $response->assertOk();
        $response->assertSee('Enterprise Referral Plan');
        $response->assertSee('450.00');
        $response->assertDontSee('45000');
    }

    // --- Empty state ---

    public function test_an_honest_empty_state_is_shown_when_no_commission_events_exist(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformResellersPage::getUrl());
        $response->assertOk();
        $response->assertSee('No commission events recorded yet');
    }

    // --- Filters ---

    public function test_status_filter_narrows_the_list(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $payable = CommissionEvent::factory()->create(['status' => CommissionEventStatus::Payable]);
        $pending = CommissionEvent::factory()->create(['status' => CommissionEventStatus::Pending]);

        $test = Livewire::test(PlatformResellersPage::class);
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

        $test = Livewire::test(PlatformResellersPage::class);
        $test->filterTable('event_type', CommissionEventType::Renewal->value);

        $test->assertCanSeeTableRecords([$renewal]);
        $test->assertCanNotSeeTableRecords([$newBusiness]);
    }

    // --- Deterministic ordering ---

    public function test_orders_deterministically_by_id_even_when_amounts_tie(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $events = CommissionEvent::factory()->count(5)->create(['amount_cents' => 5000]);

        $first = Livewire::test(PlatformResellersPage::class)->instance()->getFilteredSortedTableQuery()->pluck('id')->all();
        $second = Livewire::test(PlatformResellersPage::class)->instance()->getFilteredSortedTableQuery()->pluck('id')->all();

        $this->assertSame($first, $second, 'Tied amount rows must order identically across repeated calls.');
        $this->assertSame($events->sortByDesc('id')->pluck('id')->values()->all(), $first);
    }

    // --- Bounded pagination ---

    public function test_the_page_is_paginated(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        CommissionEvent::factory()->count(30)->create();

        $test = Livewire::test(PlatformResellersPage::class);
        $test->assertSuccessful();

        $this->assertLessThanOrEqual(25, $test->instance()->getTableRecords()->count());
    }

    // --- Positive proof: no mutating action exists ---

    public function test_no_filament_action_is_registered_and_no_commission_mutation_method_is_ever_called(): void
    {
        $source = file_get_contents(app_path('Filament/Pages/PlatformResellersPage.php'));

        $this->assertStringNotContainsString('Action::make(', $source);
        $this->assertStringNotContainsString('->action(', $source);
        $this->assertStringNotContainsString('->markPaid(', $source);
        $this->assertStringNotContainsString('->reverse(', $source);
        $this->assertStringNotContainsString('use App\Services\CommissionEventService;', $source);
    }
}
