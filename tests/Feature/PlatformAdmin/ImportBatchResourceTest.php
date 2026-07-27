<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Filament\Resources\ImportBatchResource;
use App\Filament\Resources\ImportBatchResource\Pages\ViewImportBatch;
use App\Models\Firm;
use App\Models\ImportBatch;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ImportBatchResourceTest — Phase 4 (FirmsVault Platform Admin Control
 * Center, "Operations, Governance, Support, and Configuration"),
 * Governance category, Data Exports module (import direction).
 * Read-only: route-level authorization, cross-firm listing, empty
 * state, and a positive proof no mutating action exists.
 */
final class ImportBatchResourceTest extends TestCase
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
        $this->assertFalse(ImportBatchResource::canAccess());
    }

    public function test_guest_is_redirected_from_the_import_batches_list(): void
    {
        $this->get(ImportBatchResource::getUrl())->assertRedirect('/admin/login');
    }

    public function test_a_platform_admin_with_no_role_is_forbidden(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $this->actingAs($admin, 'platform_admin')->get(ImportBatchResource::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_list_and_view_pages(): void
    {
        $firm = Firm::factory()->create(['name' => 'Import Firm']);
        $batch = ImportBatch::factory()->forFirm($firm)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $listResponse = $this->get(ImportBatchResource::getUrl());
        $listResponse->assertOk();
        $listResponse->assertSee('Import Firm');

        $viewResponse = $this->get(ViewImportBatch::getUrl(['firmUuid' => $firm->uuid, 'id' => $batch->id]));
        $viewResponse->assertOk();
    }

    public function test_viewing_a_batch_under_the_wrong_firm_404s(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $batch = ImportBatch::factory()->forFirm($firmA)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->actingAs($admin, 'platform_admin')
            ->get(ViewImportBatch::getUrl(['firmUuid' => $firmB->uuid, 'id' => $batch->id]))
            ->assertNotFound();
    }

    public function test_an_honest_empty_state_is_shown_when_no_batches_exist(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(ImportBatchResource::getUrl());
        $response->assertOk();
        $response->assertSee('No import batches found');
    }

    public function test_listing_many_batches_for_one_firm_does_not_n_plus_one(): void
    {
        $firm = Firm::factory()->create();
        ImportBatch::factory()->forFirm($firm)->create();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $onePass = [];
        DB::listen(function ($query) use (&$onePass): void {
            $onePass[] = $query->sql;
        });
        $this->get(ImportBatchResource::getUrl())->assertOk();
        $oneCount = count($onePass);

        ImportBatch::factory()->forFirm($firm)->count(9)->create();

        $tenPass = [];
        DB::listen(function ($query) use (&$tenPass): void {
            $tenPass[] = $query->sql;
        });
        $this->get(ImportBatchResource::getUrl())->assertOk();
        $tenCount = count($tenPass);

        $this->assertLessThan($oneCount + 9, $tenCount);
    }

    public function test_no_filament_mutating_action_is_registered_anywhere(): void
    {
        $resourceSource = file_get_contents(app_path('Filament/Resources/ImportBatchResource.php'));
        $viewSource = file_get_contents(app_path('Filament/Resources/ImportBatchResource/Pages/ViewImportBatch.php'));

        foreach ([$resourceSource, $viewSource] as $source) {
            $this->assertStringNotContainsString('->action(', $source);
        }
    }
}
