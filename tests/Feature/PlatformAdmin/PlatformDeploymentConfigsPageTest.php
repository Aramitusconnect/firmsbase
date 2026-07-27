<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\DeploymentMode;
use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformDeploymentConfigsPage;
use App\Models\DeploymentConfig;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use App\Services\TenantContextService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Deployment\Concerns\SetsUpDeploymentFirm;
use Tests\TestCase;

/**
 * PlatformDeploymentConfigsPageTest — Phase 4 (FirmsVault Platform
 * Admin Control Center, "Operations"). Navigation, direct-route auth,
 * the honest empty-state-for-pure-SaaS-environments disclosure,
 * per-firm-loop cross-firm read correctness (only Dedicated/
 * PrivateEnterprise firms appear, SaaS firms never do), and a positive
 * proof that no mutating action exists anywhere.
 */
final class PlatformDeploymentConfigsPageTest extends TestCase
{
    use RefreshDatabase, SetsUpDeploymentFirm;

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

    public function test_navigation_is_hidden_for_a_guest(): void
    {
        $this->assertFalse(PlatformDeploymentConfigsPage::shouldRegisterNavigation());
    }

    public function test_guest_is_redirected(): void
    {
        $this->get(PlatformDeploymentConfigsPage::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_sales_rep_is_forbidden(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $this->actingAs($admin, 'platform_admin')->get(PlatformDeploymentConfigsPage::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_page(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin')->get(PlatformDeploymentConfigsPage::getUrl())->assertOk();
    }

    public function test_empty_state_for_a_pure_saas_environment_is_honest_not_hidden(): void
    {
        $this->makeDeploymentFirm(DeploymentMode::Saas);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformDeploymentConfigsPage::getUrl());

        $response->assertOk();
        $response->assertSee('No dedicated/private-enterprise firms');
        $response->assertSee('empty for any environment where every firm runs plain SaaS mode');
    }

    public function test_only_dedicated_and_private_enterprise_firms_appear(): void
    {
        $saasFirm = $this->makeDeploymentFirm(DeploymentMode::Saas);
        $dedicatedFirm = $this->makeDeploymentFirm(DeploymentMode::Dedicated);

        app(TenantContextService::class)->runWithFirmContext($dedicatedFirm, function () use ($dedicatedFirm): void {
            DeploymentConfig::create([
                'firm_id' => $dedicatedFirm->id,
                'custom_domain' => 'dedicated.example.com',
            ]);
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformDeploymentConfigsPage::getUrl());

        $response->assertOk();
        $response->assertSee($dedicatedFirm->name);
        $response->assertSee('dedicated.example.com');
        $response->assertDontSee($saasFirm->name);
    }

    public function test_no_version_skew_is_fabricated_without_a_real_saas_version_source(): void
    {
        $dedicatedFirm = $this->makeDeploymentFirm(DeploymentMode::Dedicated);

        app(TenantContextService::class)->runWithFirmContext($dedicatedFirm, function () use ($dedicatedFirm): void {
            DeploymentConfig::create(['firm_id' => $dedicatedFirm->id]);
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(PlatformDeploymentConfigsPage::getUrl());

        $response->assertOk();
        $response->assertSee('deliberately NOT computed on this page');
    }

    public function test_no_mutating_action_exists_anywhere(): void
    {
        $source = file_get_contents(app_path('Filament/Pages/PlatformDeploymentConfigsPage.php'));

        $this->assertStringNotContainsString('Action::make(', $source);
        $this->assertStringNotContainsString('->action(', $source);
        $this->assertStringNotContainsString('recordActions(', $source);
    }

    public function test_bounded_pagination(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->makeDeploymentFirm(DeploymentMode::Dedicated);
        }

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformDeploymentConfigsPage::class);
        $test->assertSuccessful();
        $this->assertLessThanOrEqual(25, $test->instance()->getTableRecords()->count());
    }
}
