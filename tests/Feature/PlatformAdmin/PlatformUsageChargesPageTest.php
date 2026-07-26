<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Enums\UsageRollupMetric;
use App\Filament\Pages\PlatformUsageChargesPage;
use App\Models\BillingAccount;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\UsageRollup;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlatformUsageChargesPageTest — Phase 3 (FirmsVault Platform Admin
 * Control Center, "Billing and Commercial Administration"). Navigation
 * visibility, direct-route authorization, filters, the account-level
 * vs. per-firm-attribution scope distinction, empty state, deterministic
 * ordering, bounded pagination, and a positive proof that no mutating
 * action exists anywhere on this page (recordUsage() is create-only —
 * no adjustment concept is fabricated here).
 */
final class PlatformUsageChargesPageTest extends TestCase
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
        $this->assertFalse(PlatformUsageChargesPage::shouldRegisterNavigation());
    }

    public function test_navigation_is_visible_for_a_billing_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::BillingAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->assertTrue(PlatformUsageChargesPage::shouldRegisterNavigation());
    }

    // --- Direct-route authorization ---

    public function test_guest_is_redirected_from_the_usage_charges_page(): void
    {
        $this->get(PlatformUsageChargesPage::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'platform_admin')->get(PlatformUsageChargesPage::getUrl())->assertForbidden();
    }

    public function test_a_sales_rep_is_forbidden(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin')->get(PlatformUsageChargesPage::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_page(): void
    {
        $account = BillingAccount::factory()->create(['name' => 'Usage Account']);
        UsageRollup::factory()->forBillingAccount($account)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformUsageChargesPage::getUrl());
        $response->assertOk();
        $response->assertSee('Usage Account');
    }

    // --- Account-level vs per-firm attribution ---

    public function test_account_level_and_firm_attributed_rows_are_both_shown_and_distinguishable(): void
    {
        $account = BillingAccount::factory()->create();
        $firm = Firm::factory()->create(['organization_id' => null, 'billing_account_id' => $account->id, 'name' => 'Attributed Firm']);

        $accountLevel = UsageRollup::factory()->forBillingAccount($account)->create(['firm_id' => null]);
        $firmAttributed = UsageRollup::factory()->forBillingAccount($account)->create(['firm_id' => $firm->id]);

        $this->assertTrue($accountLevel->isAccountLevelAggregate());
        $this->assertFalse($firmAttributed->isAccountLevelAggregate());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformUsageChargesPage::getUrl());
        $response->assertOk();
        $response->assertSee('Attributed Firm');
        $response->assertSee('Account-level');
    }

    // --- Empty state / honesty disclosure ---

    public function test_an_honest_empty_state_and_no_manual_adjustment_disclosure_are_shown(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformUsageChargesPage::getUrl());
        $response->assertOk();
        $response->assertSee('No usage charges recorded yet');
        $response->assertSee('no update/delete/adjustment concept');
    }

    // --- Filters ---

    public function test_metric_filter_narrows_the_list(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $aiRow = UsageRollup::factory()->metric(UsageRollupMetric::AiTokens)->create();
        $storageRow = UsageRollup::factory()->metric(UsageRollupMetric::StorageBytes)->create();

        $test = Livewire::test(PlatformUsageChargesPage::class);
        $test->filterTable('metric', UsageRollupMetric::AiTokens->value);

        $test->assertCanSeeTableRecords([$aiRow]);
        $test->assertCanNotSeeTableRecords([$storageRow]);
    }

    public function test_billing_account_filter_narrows_the_list(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $accountA = BillingAccount::factory()->create();
        $accountB = BillingAccount::factory()->create();
        $rowA = UsageRollup::factory()->forBillingAccount($accountA)->create();
        $rowB = UsageRollup::factory()->forBillingAccount($accountB)->create();

        $test = Livewire::test(PlatformUsageChargesPage::class);
        $test->filterTable('billing_account_id', $accountA->id);

        $test->assertCanSeeTableRecords([$rowA]);
        $test->assertCanNotSeeTableRecords([$rowB]);
    }

    // --- Deterministic ordering ---

    public function test_orders_deterministically_when_period_starts_at_ties(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $sharedPeriod = now()->parse('2026-04-01 00:00:00');
        UsageRollup::factory()->count(5)->create(['period_starts_at' => $sharedPeriod]);

        $first = Livewire::test(PlatformUsageChargesPage::class)->instance()->getFilteredSortedTableQuery()->pluck('id')->all();
        $second = Livewire::test(PlatformUsageChargesPage::class)->instance()->getFilteredSortedTableQuery()->pluck('id')->all();

        $this->assertSame($first, $second, 'Tied period_starts_at rows must order identically across repeated calls.');
    }

    // --- Bounded pagination ---

    public function test_the_page_is_paginated(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        UsageRollup::factory()->count(30)->create();

        $test = Livewire::test(PlatformUsageChargesPage::class);
        $test->assertSuccessful();

        $this->assertLessThanOrEqual(25, $test->instance()->getTableRecords()->count());
    }

    // --- Positive proof: no mutating action exists ---

    public function test_no_filament_action_of_any_kind_is_registered_on_this_page(): void
    {
        $source = file_get_contents(app_path('Filament/Pages/PlatformUsageChargesPage.php'));

        $this->assertStringNotContainsString('Action::make(', $source);
        $this->assertStringNotContainsString('->action(', $source);
        $this->assertStringNotContainsString('->recordUsage(', $source);
        $this->assertStringNotContainsString('use App\Services\UsageRollupService;', $source);
    }
}
