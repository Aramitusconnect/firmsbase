<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\ExportJobStatus;
use App\Enums\PlatformRoleCode;
use App\Filament\Resources\ExportJobResource;
use App\Filament\Resources\ExportJobResource\Pages\ViewExportJob;
use App\Models\ExportJob;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ExportJobResourceTest — Phase 4 (FirmsVault Platform Admin Control
 * Center, "Operations, Governance, Support, and Configuration"),
 * Governance category, Data Exports module. Read-only: route-level
 * authorization, cross-firm listing, empty state, "no real file"
 * disclosure, and a positive proof no mutating action exists.
 */
final class ExportJobResourceTest extends TestCase
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
        $this->assertFalse(ExportJobResource::canAccess());
    }

    public function test_guest_is_redirected_from_the_export_jobs_list(): void
    {
        $this->get(ExportJobResource::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'platform_admin')->get(ExportJobResource::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_list_and_view_pages(): void
    {
        $firm = Firm::factory()->create(['name' => 'Export Firm']);
        $job = ExportJob::factory()->forFirm($firm)->create(['status' => ExportJobStatus::Completed->value]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $listResponse = $this->get(ExportJobResource::getUrl());
        $listResponse->assertOk();
        $listResponse->assertSee('Export Firm');

        $viewResponse = $this->get(ViewExportJob::getUrl(['firmUuid' => $firm->uuid, 'id' => $job->id]));
        $viewResponse->assertOk();
        $viewResponse->assertSee('No real file is ever produced');
    }

    public function test_viewing_a_job_under_the_wrong_firm_404s(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $job = ExportJob::factory()->forFirm($firmA)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->actingAs($admin, 'platform_admin')
            ->get(ViewExportJob::getUrl(['firmUuid' => $firmB->uuid, 'id' => $job->id]))
            ->assertNotFound();
    }

    public function test_an_honest_empty_state_is_shown_when_no_export_jobs_exist(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(ExportJobResource::getUrl());
        $response->assertOk();
        $response->assertSee('No export jobs found');
    }

    public function test_listing_many_jobs_for_one_firm_does_not_n_plus_one(): void
    {
        $firm = Firm::factory()->create();
        ExportJob::factory()->forFirm($firm)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $onePass = [];
        DB::listen(function ($query) use (&$onePass): void {
            $onePass[] = $query->sql;
        });
        $this->get(ExportJobResource::getUrl())->assertOk();
        $oneCount = count($onePass);

        ExportJob::factory()->forFirm($firm)->count(9)->create();

        $tenPass = [];
        DB::listen(function ($query) use (&$tenPass): void {
            $tenPass[] = $query->sql;
        });
        $this->get(ExportJobResource::getUrl())->assertOk();
        $tenCount = count($tenPass);

        $this->assertLessThan($oneCount + 9, $tenCount);
    }

    public function test_no_filament_mutating_action_is_registered_anywhere(): void
    {
        $resourceSource = file_get_contents(app_path('Filament/Resources/ExportJobResource.php'));
        $viewSource = file_get_contents(app_path('Filament/Resources/ExportJobResource/Pages/ViewExportJob.php'));

        foreach ([$resourceSource, $viewSource] as $source) {
            $this->assertStringNotContainsString('->action(', $source);
            $this->assertStringNotContainsString('->markInProgress(', $source);
            $this->assertStringNotContainsString('->markCompleted(', $source);
            $this->assertStringNotContainsString('->markFailed(', $source);
        }
    }
}
