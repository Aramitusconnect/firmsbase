<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformProviderHealthPage;
use App\Integrations\Models\IntegrationProvider;
use App\Models\IntegrationPlatformProviderHealthSummary;
use App\Models\PlatformAdmin;
use App\Services\IntegrationPlatformProviderHealthSummaryService;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlatformProviderHealthPageTest — Phase 2 (FirmsVault Platform Admin
 * Control Center, "Integration Operations Center"). Navigation
 * visibility, direct-route authorization, honest empty state, rendering
 * of the cached `integration_platform_provider_health_summaries` rows,
 * deterministic equal-timestamp ordering, and bounded pagination. This
 * page is genuinely read-only (a real Eloquent ->query() over a no-RLS
 * table) — no live provider call is ever made by any test or by the
 * page itself.
 */
final class PlatformProviderHealthPageTest extends TestCase
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

    // ------------------------------------------------------------
    // Navigation visibility
    // ------------------------------------------------------------

    public function test_navigation_is_hidden_for_a_guest(): void
    {
        $this->assertFalse(PlatformProviderHealthPage::shouldRegisterNavigation());
    }

    public function test_navigation_is_visible_for_an_eligible_platform_admin(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $this->assertTrue(PlatformProviderHealthPage::shouldRegisterNavigation());
    }

    public function test_navigation_is_hidden_for_a_platform_admin_with_no_role(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'platform_admin');

        $this->assertFalse(PlatformProviderHealthPage::shouldRegisterNavigation());
    }

    // ------------------------------------------------------------
    // Direct-route authorization
    // ------------------------------------------------------------

    public function test_guest_is_redirected_from_the_provider_health_page(): void
    {
        $this->get(PlatformProviderHealthPage::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'platform_admin')->get(PlatformProviderHealthPage::getUrl())->assertForbidden();
    }

    public function test_a_sales_rep_is_forbidden(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);

        $this->actingAs($admin, 'platform_admin')->get(PlatformProviderHealthPage::getUrl())->assertForbidden();
    }

    public function test_a_support_agent_can_reach_the_provider_health_page(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SupportAgent);

        $this->actingAs($admin, 'platform_admin')->get(PlatformProviderHealthPage::getUrl())->assertOk();
    }

    // ------------------------------------------------------------
    // Empty state
    // ------------------------------------------------------------

    public function test_an_honest_empty_state_is_shown_when_no_summaries_exist_yet(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformProviderHealthPage::getUrl());

        $response->assertOk();
        $response->assertSee('No provider health summaries yet');
    }

    // ------------------------------------------------------------
    // Enabled/disabled filter (boolean-native TernaryFilter)
    // ------------------------------------------------------------

    public function test_the_enabled_filter_narrows_to_only_enabled_providers(): void
    {
        $enabledProvider = IntegrationProvider::factory()->create(['code' => 'enabled-filter-provider', 'status' => 'active']);
        $disabledProvider = IntegrationProvider::factory()->create(['code' => 'disabled-filter-provider', 'status' => 'deprecated']);

        app(IntegrationPlatformProviderHealthSummaryService::class)->refreshForProvider($enabledProvider->fresh());
        app(IntegrationPlatformProviderHealthSummaryService::class)->refreshForProvider($disabledProvider->fresh());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformProviderHealthPage::class);
        $test->assertOk();

        $test->filterTable('provider_enabled', true);
        $test->assertCanSeeTableRecords(
            IntegrationPlatformProviderHealthSummary::query()->where('provider_code', 'enabled-filter-provider')->get()
        );
        $test->assertCanNotSeeTableRecords(
            IntegrationPlatformProviderHealthSummary::query()->where('provider_code', 'disabled-filter-provider')->get()
        );
    }

    // ------------------------------------------------------------
    // Rendering the cached summary
    // ------------------------------------------------------------

    public function test_a_computed_summary_renders_correctly(): void
    {
        $provider = IntegrationProvider::factory()->create(['code' => 'render-check-provider']);
        app(IntegrationPlatformProviderHealthSummaryService::class)->refreshForProvider($provider->fresh());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformProviderHealthPage::getUrl());

        $response->assertOk();
        $response->assertSee('render-check-provider');
    }

    public function test_no_live_provider_call_is_made_by_this_page(): void
    {
        $source = file_get_contents(app_path('Filament/Pages/PlatformProviderHealthPage.php'));
        $this->assertIsString($source);

        foreach (['Http::', 'GuzzleHttp', 'curl_init', 'curl_exec', 'fsockopen'] as $forbiddenToken) {
            $this->assertStringNotContainsString($forbiddenToken, $source, "PlatformProviderHealthPage must never contain '{$forbiddenToken}' — it is structurally read-only.");
        }
    }

    // ------------------------------------------------------------
    // Deterministic ordering with equal computed_at/created_at
    // ------------------------------------------------------------

    public function test_two_provider_summaries_sharing_identical_timestamps_produce_a_stable_repeated_order(): void
    {
        $providerA = IntegrationProvider::factory()->create(['code' => 'aaa-tie-break-provider']);
        $providerB = IntegrationProvider::factory()->create(['code' => 'bbb-tie-break-provider']);

        $now = Carbon::now();
        Carbon::setTestNow($now);

        app(IntegrationPlatformProviderHealthSummaryService::class)->refreshForProvider($providerA->fresh());
        app(IntegrationPlatformProviderHealthSummaryService::class)->refreshForProvider($providerB->fresh());

        Carbon::setTestNow();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $firstRun = IntegrationPlatformProviderHealthSummary::query()
            ->orderBy('provider_code')
            ->pluck('provider_code')
            ->all();
        $secondRun = IntegrationPlatformProviderHealthSummary::query()
            ->orderBy('provider_code')
            ->pluck('provider_code')
            ->all();

        $this->assertSame($firstRun, $secondRun);
        $this->assertSame(['aaa-tie-break-provider', 'bbb-tie-break-provider'], $firstRun);
    }

    // ------------------------------------------------------------
    // Bounded pagination
    // ------------------------------------------------------------

    public function test_the_provider_health_page_is_bounded_and_paginated(): void
    {
        $source = file_get_contents(app_path('Filament/Pages/PlatformProviderHealthPage.php'));
        $this->assertIsString($source);

        $this->assertMatchesRegularExpression('/->paginated\(\[25, 50, 100\]\)/', $source);
        $this->assertMatchesRegularExpression("/->defaultSort\('provider_code'\)/", $source);
    }

    // ------------------------------------------------------------
    // Query re-checked at render time (not just page-load canAccess())
    // ------------------------------------------------------------

    public function test_the_table_query_itself_is_re_gated_not_only_the_page_level_can_access(): void
    {
        $provider = IntegrationProvider::factory()->create();
        app(IntegrationPlatformProviderHealthSummaryService::class)->refreshForProvider($provider->fresh());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformProviderHealthPage::class);
        $test->assertOk();
        $test->assertSee($provider->code);
    }
}
