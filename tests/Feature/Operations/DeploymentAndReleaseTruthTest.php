<?php

namespace Tests\Feature\Operations;

use App\Enums\DeploymentMode;
use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformDeploymentConfigsPage;
use App\Filament\Pages\PlatformOperationsOverviewPage;
use App\Models\DeploymentConfig;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\OperationsOverviewService;
use App\Services\PlatformRoleService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Operations Control Plane — deployment and release truth.
 *
 * Two conflations are guarded here. First, a configuration flag
 * saying isolated_database = true is a statement of intent; it is not
 * evidence that a dedicated database exists. Second, a version a
 * deployment reports about itself is not a verified release, and
 * without an authoritative desired version there is no skew to
 * compute — the honest answer is Not Calculable, not zero.
 */
class DeploymentAndReleaseTruthTest extends TestCase
{
    use RefreshDatabase;

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    private function dedicatedFirmWithDeclaredIsolation(): Firm
    {
        $firm = Firm::factory()->create(['deployment_mode' => DeploymentMode::Dedicated->value]);

        app(TenantContextService::class)->runWithFirmContext($firm, fn () => DeploymentConfig::create([
            'firm_id' => $firm->id,
            'custom_domain' => 'vault.example.test',
            'isolated_database' => true,
            'isolated_storage' => true,
        ]));

        return $firm;
    }

    // --- Declared vs verified ---

    public function test_declared_isolation_is_labelled_declared_and_never_verified(): void
    {
        $this->dedicatedFirmWithDeclaredIsolation();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformDeploymentConfigsPage::getUrl());

        $response->assertOk();
        $response->assertSee('Declared isolated DB');
        $response->assertSee('Verification Not Available');
        $response->assertSee('DECLARED IS NOT VERIFIED');
    }

    public function test_the_overview_reports_no_infrastructure_verification_capability(): void
    {
        $deployments = app(OperationsOverviewService::class)->deployments();

        $this->assertFalse($deployments['infrastructure_verification_available']);
    }

    // --- Heartbeat freshness is measurable, staleness is not decidable ---

    public function test_heartbeat_staleness_is_reported_as_undecidable_rather_than_guessed(): void
    {
        $deployments = app(OperationsOverviewService::class)->deployments();

        $this->assertFalse(
            $deployments['heartbeat_staleness_decidable'],
            'no expected cadence exists, so no overdue verdict may be produced',
        );
        $this->assertStringContainsString('No expected heartbeat cadence is defined', $deployments['heartbeat_staleness_reason']);
    }

    public function test_a_deployment_that_never_reported_shows_never_rather_than_a_date(): void
    {
        $this->dedicatedFirmWithDeclaredIsolation();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformDeploymentConfigsPage::getUrl());

        $response->assertOk();
        $response->assertSee('Never reported');
        $response->assertSee('Never Observed');
    }

    // --- Version truth ---

    public function test_version_skew_is_not_calculable_and_is_never_reported_as_zero(): void
    {
        $release = app(OperationsOverviewService::class)->release();

        $this->assertFalse($release['version_skew_calculable']);
        $this->assertArrayNotHasKey('version_skew_count', $release);

        $deployments = app(OperationsOverviewService::class)->deployments();
        $this->assertFalse($deployments['version_skew_calculable']);
    }

    public function test_no_desired_version_source_exists(): void
    {
        $this->assertFalse(app(OperationsOverviewService::class)->release()['desired_version_available']);
    }

    public function test_the_source_commit_is_never_presented_as_a_verified_release(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')
            ->get(PlatformOperationsOverviewPage::getUrl());

        $response->assertOk();
        $response->assertSee('Current SaaS release: Unknown');
        $response->assertSee('Source commit of the running checkout');
    }

    // --- Naming ---

    public function test_the_page_is_named_for_what_it_actually_lists(): void
    {
        // "Deployments" implied a SaaS release history that does not
        // exist. This page lists dedicated/private-enterprise firms.
        $this->assertSame('Dedicated Deployments', PlatformDeploymentConfigsPage::getNavigationLabel());
    }

    public function test_the_page_disclaims_being_a_saas_release_history(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformDeploymentConfigsPage::getUrl());

        $response->assertOk();
        $response->assertSee('NOT FirmsVault&#039;s own SaaS release/CI-CD history', false);
    }

    // --- Cross-firm safety ---

    public function test_only_dedicated_and_private_firms_are_listed(): void
    {
        $this->dedicatedFirmWithDeclaredIsolation();
        $saasFirm = Firm::factory()->create([
            'name' => 'Plain Saas Firm Zzz',
            'deployment_mode' => DeploymentMode::Saas->value,
        ]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformDeploymentConfigsPage::getUrl());

        $response->assertOk();
        $response->assertDontSee($saasFirm->name);
    }

    public function test_the_overview_counts_only_dedicated_deployments(): void
    {
        $this->dedicatedFirmWithDeclaredIsolation();
        Firm::factory()->create(['deployment_mode' => DeploymentMode::Saas->value]);

        $this->assertSame(1, app(OperationsOverviewService::class)->deployments()['dedicated_count']);
    }
}
