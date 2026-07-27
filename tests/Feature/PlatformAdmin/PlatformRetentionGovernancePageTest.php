<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Enums\RetentionPolicyStatus;
use App\Enums\RetentionRecordType;
use App\Filament\Pages\PlatformRetentionGovernancePage;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\RetentionPolicy;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlatformRetentionGovernancePageTest — Phase 4 (FirmsVault Platform
 * Admin Control Center, "Operations, Governance, Support, and
 * Configuration"), Governance category, Retention module. Navigation
 * visibility, direct-route authorization, the sweep-history limitation
 * disclosure, real retention-policy rendering, filters, deterministic
 * ordering, bounded pagination, empty state, and a positive proof that
 * no mutating action (supersede()) is ever registered or called.
 */
final class PlatformRetentionGovernancePageTest extends TestCase
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
        $this->assertFalse(PlatformRetentionGovernancePage::shouldRegisterNavigation());
    }

    public function test_navigation_is_visible_for_a_super_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->assertTrue(PlatformRetentionGovernancePage::shouldRegisterNavigation());
    }

    // --- Direct-route authorization ---

    public function test_guest_is_redirected_from_the_retention_page(): void
    {
        $this->get(PlatformRetentionGovernancePage::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'platform_admin')->get(PlatformRetentionGovernancePage::getUrl())->assertForbidden();
    }

    public function test_a_sales_rep_is_forbidden(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin')->get(PlatformRetentionGovernancePage::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_page(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformRetentionGovernancePage::getUrl());
        $response->assertOk();
    }

    // --- Honest disclosure ---

    public function test_the_page_honestly_discloses_sweep_history_is_not_shown(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformRetentionGovernancePage::getUrl());
        $response->assertOk();
        $response->assertSee('Sweep History Is Not Shown Here');
        $response->assertSee('no database table records sweep history');
    }

    public function test_the_governance_registry_section_is_shown(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformRetentionGovernancePage::getUrl());
        $response->assertOk();
        $response->assertSee('Integration Retention Governance Registry');
        $response->assertSee('Sync Runs');
    }

    // --- Real retention policy rendering ---

    public function test_platform_default_and_firm_override_policies_are_both_shown(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $firm = Firm::factory()->create(['name' => 'Retention Firm']);
        RetentionPolicy::factory()->create(['record_type' => RetentionRecordType::Matter]);
        RetentionPolicy::factory()->forFirm($firm)->create(['record_type' => RetentionRecordType::Client]);

        $response = $this->get(PlatformRetentionGovernancePage::getUrl());
        $response->assertOk();
        $response->assertSee('Retention Firm');
    }

    // --- Filters ---

    public function test_record_type_filter_narrows_the_list(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $matterPolicy = RetentionPolicy::factory()->create(['record_type' => RetentionRecordType::Matter]);
        $clientPolicy = RetentionPolicy::factory()->create(['record_type' => RetentionRecordType::Client]);

        $test = Livewire::test(PlatformRetentionGovernancePage::class);
        $test->filterTable('record_type', RetentionRecordType::Client->value);

        $test->assertCanSeeTableRecords([$clientPolicy]);
        $test->assertCanNotSeeTableRecords([$matterPolicy]);
    }

    public function test_status_filter_narrows_the_list(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $active = RetentionPolicy::factory()->create(['status' => RetentionPolicyStatus::Active]);
        $archived = RetentionPolicy::factory()->create(['status' => RetentionPolicyStatus::Archived]);

        $test = Livewire::test(PlatformRetentionGovernancePage::class);
        $test->filterTable('status', RetentionPolicyStatus::Archived->value);

        $test->assertCanSeeTableRecords([$archived]);
        $test->assertCanNotSeeTableRecords([$active]);
    }

    // --- Deterministic ordering ---

    public function test_orders_deterministically_by_id_when_created_together(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $policies = RetentionPolicy::factory()->count(5)->create();

        $first = Livewire::test(PlatformRetentionGovernancePage::class)->instance()->getFilteredSortedTableQuery()->pluck('id')->all();
        $second = Livewire::test(PlatformRetentionGovernancePage::class)->instance()->getFilteredSortedTableQuery()->pluck('id')->all();

        $this->assertSame($first, $second);
        $this->assertSame($policies->sortByDesc('id')->pluck('id')->values()->all(), $first);
    }

    // --- Bounded pagination ---

    public function test_the_page_is_paginated(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        RetentionPolicy::factory()->count(30)->create();

        $test = Livewire::test(PlatformRetentionGovernancePage::class);
        $test->assertSuccessful();

        $this->assertLessThanOrEqual(25, $test->instance()->getTableRecords()->count());
    }

    // --- Empty state ---

    public function test_an_honest_empty_state_is_shown_when_no_policies_exist(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformRetentionGovernancePage::getUrl());
        $response->assertOk();
        $response->assertSee('No retention policies found');
    }

    // --- Positive proof: no mutating action exists ---

    public function test_no_filament_action_is_registered_and_supersede_is_never_called(): void
    {
        $source = file_get_contents(app_path('Filament/Pages/PlatformRetentionGovernancePage.php'));

        $this->assertStringNotContainsString('Action::make(', $source);
        $this->assertStringNotContainsString('->action(', $source);
        $this->assertStringNotContainsString('->supersede(', $source);
    }
}
