<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Filament\Resources\MigrationProjectResource;
use App\Filament\Resources\MigrationProjectResource\Pages\ViewMigrationProject;
use App\Models\Firm;
use App\Models\MigrationProject;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MigrationProjectResourceTest — Phase 4 (FirmsVault Platform Admin
 * Control Center, "Operations, Governance, Support, and
 * Configuration"), Governance category, Data Exports module (migration
 * direction). Read-only: route-level authorization, cross-firm listing,
 * empty state, and a positive proof no mutating action exists.
 */
final class MigrationProjectResourceTest extends TestCase
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

    public function test_navigation_is_hidden_when_no_admin_is_authenticated(): void
    {
        $this->assertFalse(MigrationProjectResource::canAccess());
    }

    public function test_guest_is_redirected_from_the_migration_projects_list(): void
    {
        $this->get(MigrationProjectResource::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'platform_admin')->get(MigrationProjectResource::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_list_and_view_pages(): void
    {
        $firm = Firm::factory()->create(['name' => 'Migration Firm']);
        $project = MigrationProject::factory()->forFirm($firm)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $listResponse = $this->get(MigrationProjectResource::getUrl());
        $listResponse->assertOk();
        $listResponse->assertSee('Migration Firm');

        $viewResponse = $this->get(ViewMigrationProject::getUrl(['firmUuid' => $firm->uuid, 'id' => $project->id]));
        $viewResponse->assertOk();
        $viewResponse->assertSee('no real external API call is ever made');
    }

    public function test_viewing_a_project_under_the_wrong_firm_404s(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $project = MigrationProject::factory()->forFirm($firmA)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->actingAs($admin, 'platform_admin')
            ->get(ViewMigrationProject::getUrl(['firmUuid' => $firmB->uuid, 'id' => $project->id]))
            ->assertNotFound();
    }

    public function test_an_honest_empty_state_is_shown_when_no_projects_exist(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(MigrationProjectResource::getUrl());
        $response->assertOk();
        $response->assertSee('No migration projects found');
    }

    public function test_listing_many_projects_for_one_firm_does_not_n_plus_one(): void
    {
        $firm = Firm::factory()->create();
        MigrationProject::factory()->forFirm($firm)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $onePass = [];
        DB::listen(function ($query) use (&$onePass): void {
            $onePass[] = $query->sql;
        });
        $this->get(MigrationProjectResource::getUrl())->assertOk();
        $oneCount = count($onePass);

        MigrationProject::factory()->forFirm($firm)->count(9)->create();

        $tenPass = [];
        DB::listen(function ($query) use (&$tenPass): void {
            $tenPass[] = $query->sql;
        });
        $this->get(MigrationProjectResource::getUrl())->assertOk();
        $tenCount = count($tenPass);

        $this->assertLessThan($oneCount + 9, $tenCount);
    }

    public function test_no_filament_mutating_action_is_registered_anywhere(): void
    {
        $resourceSource = file_get_contents(app_path('Filament/Resources/MigrationProjectResource.php'));
        $viewSource = file_get_contents(app_path('Filament/Resources/MigrationProjectResource/Pages/ViewMigrationProject.php'));

        foreach ([$resourceSource, $viewSource] as $source) {
            $this->assertStringNotContainsString('->action(', $source);
        }
    }
}
