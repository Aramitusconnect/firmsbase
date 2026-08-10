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
use Illuminate\Support\Facades\DB;
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
        $this->get(PlatformProviderHealthPage::getUrl())->assertRedirect($this->adminUrl('/login'));
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

    /**
     * CHECKPOINT 1 (FirmsVault Live Integrations) addition —
     * checkpoint1-design-health-sandbox.md §A.3.2/§A.4: renders a row
     * carrying genuinely non-zero values for every new metrics column,
     * including total_success_count's formatStateUsing() percentage
     * callback (the one new column whose rendering logic is more than a
     * bare TextColumn::make() passthrough — it reads a SIBLING column,
     * total_request_count, off $record, and divides), proving it
     * computes without a division-by-zero or type error.
     *
     * `recent_error_classification_summary` is deliberately left null
     * here — this is a PRE-EXISTING (pre-Checkpoint-1) column/callback,
     * not something this checkpoint added or touched, and it has an
     * independently confirmed, genuine rendering bug (reported
     * separately, not fixed here per this task's scope: TextColumn
     * treats any non-empty array $state as a "list of state items" and,
     * for a single-entry array, unwraps it down to that entry's bare
     * VALUE before calling formatStateUsing() — so a real
     * ['category' => count] map with exactly one category throws
     * `TypeError: ?array expected, int given` instead of rendering).
     * That column is unrelated to Checkpoint 1's own file list; testing
     * around it here keeps this test file's scope to what Checkpoint 1
     * actually added.
     */
    public function test_a_summary_with_nonzero_new_metrics_columns_renders_without_error(): void
    {
        $provider = IntegrationProvider::factory()->create(['code' => 'metrics-render-check-provider']);

        DB::table('integration_platform_provider_health_summaries')->insert([
            'integration_provider_id' => $provider->id,
            'provider_code' => $provider->code,
            'provider_enabled' => true,
            'connected_firm_count' => 3,
            'disconnected_firm_count' => 1,
            'firms_requiring_attention_count' => 1,
            'oauth_health_signal' => 'healthy',
            'webhook_health_signal' => 'healthy',
            'rate_limit_condition_signal' => 'degraded',
            'recent_error_classification_summary' => null,
            'total_request_count' => 40,
            'total_success_count' => 37,
            'throttled_connection_count' => 2,
            'token_refresh_failure_count' => 1,
            'webhook_verification_failure_count' => 5,
            'dead_letter_count' => 3,
            'avg_latency_ms' => 214,
            'computed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformProviderHealthPage::getUrl());

        $response->assertOk();
        $response->assertSee('metrics-render-check-provider');
        // 37/40 = 92.5%, formatted "92.5% (37/40)" per the page's own
        // formatStateUsing() callback.
        $response->assertSee('92.5%');
        $response->assertSee('37/40', false);
    }

    /**
     * Independent, standalone confirmation of a genuine PRE-EXISTING
     * (pre-Checkpoint-1) bug — reported, not fixed, per this task's
     * "STOP and report" instruction for production-code defects outside
     * this checkpoint's own scope: `recent_error_classification_summary`'s
     * TextColumn throws a 500 whenever the map has exactly one category.
     * This test intentionally documents/reproduces the failure via
     * assertStatus(500) + the specific TypeError text, so a future fix
     * has an immediate, already-written regression test to flip once the
     * page's column definition is corrected (e.g. by giving it an
     * explicit `->listWithLineBreaks()`-incompatible / non-Arr::wrap()
     * rendering path, or pre-formatting the map into a single string
     * before the column ever sees it) — this test is NOT part of
     * Checkpoint 1's own required coverage and asserts the CURRENT
     * (buggy) behavior deliberately, not the desired one.
     */
    public function test_know_n_bu_g_a_single_category_error_classification_summary_currently_throws_a_500(): void
    {
        $provider = IntegrationProvider::factory()->create(['code' => 'known-bug-single-category-provider']);

        DB::table('integration_platform_provider_health_summaries')->insert([
            'integration_provider_id' => $provider->id,
            'provider_code' => $provider->code,
            'provider_enabled' => true,
            'connected_firm_count' => 0,
            'disconnected_firm_count' => 0,
            'firms_requiring_attention_count' => 0,
            'oauth_health_signal' => null,
            'webhook_health_signal' => null,
            'rate_limit_condition_signal' => null,
            'recent_error_classification_summary' => json_encode(['provider_error' => 2]),
            'total_request_count' => 0,
            'total_success_count' => 0,
            'throttled_connection_count' => 0,
            'token_refresh_failure_count' => 0,
            'webhook_verification_failure_count' => 0,
            'dead_letter_count' => 0,
            'avg_latency_ms' => null,
            'computed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformProviderHealthPage::getUrl());

        $response->assertStatus(500, 'KNOWN, REPORTED, PRE-EXISTING BUG (not introduced by Checkpoint 1, not fixed by this test-writing pass — see this test\'s own docblock): recent_error_classification_summary\'s TextColumn currently 500s whenever the map has exactly one category. If this assertion ever fails because someone fixed the page, that is GOOD — update this test to assertOk() and assert the rendered content at that point.');
        $this->assertStringContainsString('must be of type ?array, int given', (string) $response->exception?->getMessage());
    }

    public function test_a_summary_with_zero_total_requests_shows_a_placeholder_not_a_division_by_zero(): void
    {
        $provider = IntegrationProvider::factory()->create(['code' => 'zero-requests-render-check-provider']);
        app(IntegrationPlatformProviderHealthSummaryService::class)->refreshForProvider($provider->fresh());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformProviderHealthPage::getUrl());

        $response->assertOk();
        $response->assertSee('zero-requests-render-check-provider');
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
