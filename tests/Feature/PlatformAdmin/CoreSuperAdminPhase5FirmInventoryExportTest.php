<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Enums\PlatformRoleCode;
use App\Filament\Resources\FirmResource;
use App\Filament\Resources\FirmResource\Pages\ListFirms;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * CoreSuperAdminPhase5FirmInventoryExportTest — CORE SuperAdmin
 * mission (admin/core-superadmin-security), Phase 5, section 61.
 * ExportFirmInventoryCsvAction mirrors the codebase's established
 * League\Csv/streamDownload export pattern (already proven twice
 * elsewhere, see PlatformIntegrationOverviewCsvExportAndCardsTest's
 * own mountAction()/callMountedAction()/assertFileDownloaded()
 * convention, reused identically here) — proves it is reachable for a
 * Firms-list-eligible admin, actually downloads a CSV containing real
 * firm data, and is unreachable for an admin without Firms-list
 * access.
 */
final class CoreSuperAdminPhase5FirmInventoryExportTest extends TestCase
{
    use RefreshDatabase;

    private function platformAdmin(array $attributes = []): PlatformAdmin
    {
        return PlatformAdmin::factory()->create(array_merge([
            'is_active' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
        ], $attributes));
    }

    public function test_an_eligible_admin_can_download_a_csv_of_real_firms(): void
    {
        $admin = $this->platformAdmin();
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);

        $firm = Firm::factory()->activated()->create(['name' => 'CSV Inventory Test Firm LLC']);

        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListFirms::class);
        $test->assertOk();

        $test->mountAction('exportFirmInventoryCsv');
        $test->callMountedAction();

        $test->assertFileDownloaded();

        $downloadedContent = base64_decode(data_get($test->effects, 'download.content'));
        $this->assertStringContainsString('Firm ID', $downloadedContent);
        $this->assertStringContainsString('CSV Inventory Test Firm LLC', $downloadedContent);
        $this->assertStringContainsString((string) $firm->uuid, $downloadedContent);
    }

    public function test_an_admin_with_no_role_cannot_reach_the_firms_list_or_its_export_action(): void
    {
        $admin = $this->platformAdmin();

        // A no-role admin is forbidden from the Firms list route itself
        // (FirmResource::canAccess() -> FirmPolicy::viewAny() ->
        // canAccessPlatformAdministration()), so the export action is
        // unreachable a full layer above its own ->visible() gate —
        // never independently more permissive.
        $this->actingAs($admin, 'platform_admin')
            ->get(FirmResource::getUrl())
            ->assertForbidden();
    }
}
