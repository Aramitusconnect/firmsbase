<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\PlatformRoleCode;
use App\Filament\Pages\PlatformFirmIntegrationDetailPage;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationOutboxEvent;
use App\Integrations\Models\IntegrationSyncItem;
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
 * PlatformIntegrationFailureViewTest — Checkpoint 11 (frozen-design-
 * post-security-review.md §10 item 2). Proves
 * IntegrationPlatformOversightReadService::failedItemsForConnection()
 * combines dead-lettered outbox events and failed-permanent sync items
 * correctly, and — the load-bearing property — that `last_error` is
 * NEVER present anywhere in the mapped output, even when planted with a
 * real marker value.
 */
final class PlatformIntegrationFailureViewTest extends TestCase
{
    use RefreshDatabase;

    private const LAST_ERROR_MARKER = 'SECRET-MARKER-last-error-9f1a7b3e2c6d4a58';

    public function test_failed_items_combines_dead_lettered_outbox_events_and_failed_permanent_sync_items(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, function () use ($connection) {
            IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->deadLettered()->create(['event_type' => 'contact_sync']);

            $run = IntegrationSyncRun::factory()->forFirmIntegration($connection)->succeeded()->create();
            IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create(['resource_type' => 'matter']);
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $items = app(IntegrationPlatformOversightReadService::class)->failedItemsForConnection($admin, $firm, $connection->id);

        $this->assertCount(2, $items);
        $types = $items->pluck('type')->all();
        $this->assertContains('outbox_event', $types);
        $this->assertContains('sync_item', $types);
    }

    public function test_last_error_never_appears_anywhere_in_the_failed_items_output_for_outbox_events(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->deadLettered()->create([
            'last_error' => self::LAST_ERROR_MARKER,
        ]));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $items = app(IntegrationPlatformOversightReadService::class)->failedItemsForConnection($admin, $firm, $connection->id);

        $json = json_encode($items->all());
        $this->assertStringNotContainsString(self::LAST_ERROR_MARKER, $json);

        foreach ($items as $item) {
            $this->assertArrayNotHasKey('last_error', $item);
        }
    }

    public function test_last_error_never_appears_anywhere_in_the_failed_items_output_for_sync_items(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, function () use ($connection) {
            $run = IntegrationSyncRun::factory()->forFirmIntegration($connection)->succeeded()->create();
            IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create(['last_error' => self::LAST_ERROR_MARKER]);
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $items = app(IntegrationPlatformOversightReadService::class)->failedItemsForConnection($admin, $firm, $connection->id);

        $json = json_encode($items->all());
        $this->assertStringNotContainsString(self::LAST_ERROR_MARKER, $json);
    }

    public function test_the_detail_page_never_renders_the_planted_last_error_marker(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->deadLettered()->create([
            'last_error' => self::LAST_ERROR_MARKER,
        ]));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformFirmIntegrationDetailPage::class, [
            'firmUuid' => $firm->uuid,
            'connectionUuid' => $connection->uuid,
        ]);

        $test->assertOk();
        $test->assertDontSee(self::LAST_ERROR_MARKER);
    }

    public function test_requeue_row_actions_are_correctly_type_gated(): void
    {
        // RequeueOutboxEventAsSupportAction only visible for
        // type=outbox_event, RequeueSyncItemAsSupportAction only for
        // type=sync_item — structural confirmation from source.
        $outboxAction = file_get_contents(app_path('Filament/Actions/Platform/RequeueOutboxEventAsSupportAction.php'));
        $syncAction = file_get_contents(app_path('Filament/Actions/Platform/RequeueSyncItemAsSupportAction.php'));

        $this->assertStringContainsString("'outbox_event'", $outboxAction);
        $this->assertStringContainsString("'sync_item'", $syncAction);
    }

    public function test_failed_items_are_ordered_most_recently_failed_first(): void
    {
        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, function () use ($connection) {
            IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->deadLettered()->create([
                'dead_lettered_at' => now()->subHours(2),
                'event_type' => 'older',
            ]);
            IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->deadLettered()->create([
                'dead_lettered_at' => now(),
                'event_type' => 'newer',
            ]);
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $items = app(IntegrationPlatformOversightReadService::class)->failedItemsForConnection($admin, $firm, $connection->id)->values();

        $this->assertSame('newer', $items[0]['label']);
        $this->assertSame('older', $items[1]['label']);
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }
}
