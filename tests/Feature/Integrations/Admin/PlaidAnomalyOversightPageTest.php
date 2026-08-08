<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\DeploymentMode;
use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlaidAnomalyOversightPage;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\TimelineEvent;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlaidAnomalyOversightPageTest — regression coverage for the Admin
 * acceptance-audit-found 500: this page's Firm::query()->get(['id',
 * 'uuid', 'name']) loop passed the resulting PARTIAL Firm model
 * straight into TenantContextService::runWithFirmContext(), which
 * (per TenantContextResolver::resolveForFirm()) reads
 * deployment_mode/organization_id off that same instance -- columns
 * this restricted select never loaded, producing a bare TypeError.
 * Fixed by passing $firm->id (an int) instead of the partial model,
 * mirroring the established, working precedent in
 * DetectProviderUsageAnomaliesJob/ExpireStaleProviderReservationsJob.
 */
final class PlaidAnomalyOversightPageTest extends TestCase
{
    use RefreshDatabase;

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    public function test_guest_is_redirected_from_the_page(): void
    {
        $this->get(PlaidAnomalyOversightPage::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'platform_admin')
            ->get(PlaidAnomalyOversightPage::getUrl())
            ->assertForbidden();
    }

    public function test_a_super_admin_can_load_the_page_with_only_saas_firms(): void
    {
        Firm::factory()->create(['deployment_mode' => DeploymentMode::Saas]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->actingAs($admin, 'platform_admin')
            ->get(PlaidAnomalyOversightPage::getUrl())
            ->assertOk();
    }

    /**
     * The exact regression scenario: a non-default deployment_mode
     * firm in the loop must never crash the page. Before the fix,
     * EVERY deployment_mode value crashed identically (the bug was in
     * the query's column list, not any particular value), but a
     * non-default value here proves the fix doesn't rely on the
     * default happening to coincide with what the code omitted to
     * load.
     */
    public function test_the_page_loads_safely_with_a_mix_of_deployment_modes(): void
    {
        Firm::factory()->create(['deployment_mode' => DeploymentMode::Saas]);
        Firm::factory()->create(['deployment_mode' => DeploymentMode::Dedicated]);
        Firm::factory()->create(['deployment_mode' => DeploymentMode::PrivateEnterprise]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        Livewire::test(PlaidAnomalyOversightPage::class)->assertOk();
    }

    public function test_anomaly_events_from_different_firms_never_cross_attribute(): void
    {
        $firmA = Firm::factory()->create(['name' => 'Anomaly Firm A', 'deployment_mode' => DeploymentMode::Saas]);
        $firmB = Firm::factory()->create(['name' => 'Anomaly Firm B', 'deployment_mode' => DeploymentMode::Dedicated]);

        $this->runWithFirmContext($firmA, fn () => TimelineEvent::factory()->forFirm($firmA)->create([
            'event_type' => 'provider_billing.anomaly_detected',
            'occurred_at' => now(),
            'metadata_json' => ['product' => 'transactions', 'current_window_count' => 50, 'baseline_daily_average' => 5],
        ]));

        $this->runWithFirmContext($firmB, fn () => TimelineEvent::factory()->forFirm($firmB)->create([
            'event_type' => 'provider_billing.anomaly_detected',
            'occurred_at' => now(),
            'metadata_json' => ['product' => 'identity', 'current_window_count' => 80, 'baseline_daily_average' => 8],
        ]));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $response = $this->get(PlaidAnomalyOversightPage::getUrl());
        $response->assertOk();
        $response->assertSee('Anomaly Firm A');
        $response->assertSee('Anomaly Firm B');
        $response->assertSee('transactions');
        $response->assertSee('identity');
    }
}
