<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformFirmIntegrationDetailPage;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationSyncRun;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\IntegrationPlatformOversightReadService;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlatformIntegrationSyncViewTest — Checkpoint 11 (frozen-design-post-
 * security-review.md §7). Proves
 * IntegrationPlatformOversightReadService::syncHistoryForConnection()
 * returns the connection's real sync run history, correctly ordered
 * (most recent first) and scoped to the exact connection/firm.
 */
final class PlatformIntegrationSyncViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_history_returns_runs_for_the_connection_ordered_most_recent_first(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, function () use ($connection) {
            IntegrationSyncRun::factory()->forFirmIntegration($connection)->succeeded()->create(['created_at' => now()->subHours(2)]);
            IntegrationSyncRun::factory()->forFirmIntegration($connection)->failed()->create(['created_at' => now()->subHour()]);
            IntegrationSyncRun::factory()->forFirmIntegration($connection)->running()->create(['created_at' => now()]);
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $history = app(IntegrationPlatformOversightReadService::class)->syncHistoryForConnection($admin, $firm, $connection->id);

        $this->assertCount(3, $history);
        $this->assertSame('running', $history[0]['status']);
        $this->assertSame('failed', $history[1]['status']);
        $this->assertSame('succeeded', $history[2]['status']);
    }

    public function test_sync_history_never_includes_a_run_from_a_different_connection(): void
    {
        $firm = Firm::factory()->activated()->create();
        [$connection, $otherConnection] = $this->runWithFirmContext($firm, fn () => [
            FirmIntegration::factory()->forFirm($firm)->create(),
            FirmIntegration::factory()->forFirm($firm)->create(),
        ]);

        $this->runWithFirmContext($firm, function () use ($connection, $otherConnection) {
            IntegrationSyncRun::factory()->forFirmIntegration($connection)->succeeded()->create();
            IntegrationSyncRun::factory()->forFirmIntegration($otherConnection)->succeeded()->create();
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $history = app(IntegrationPlatformOversightReadService::class)->syncHistoryForConnection($admin, $firm, $connection->id);

        $this->assertCount(1, $history);
    }

    public function test_a_connection_with_no_sync_runs_returns_an_empty_history(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $history = app(IntegrationPlatformOversightReadService::class)->syncHistoryForConnection($admin, $firm, $connection->id);

        $this->assertCount(0, $history);
    }

    public function test_the_detail_page_renders_sync_history_content(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration($connection)->succeeded()->create(['resource_type' => 'matters']));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformFirmIntegrationDetailPage::class, [
            'firmUuid' => $firm->uuid,
            'connectionUuid' => $connection->uuid,
        ]);

        $test->assertOk();
        $test->assertSee('matters');
        $test->assertSee('succeeded');
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }
}
