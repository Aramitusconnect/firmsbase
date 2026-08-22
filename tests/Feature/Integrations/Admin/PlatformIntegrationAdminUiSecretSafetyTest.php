<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\PlatformRoleCode;
use App\Enums\SupportAccessSessionStatus;
use App\Filament\Pages\PlatformFirmIntegrationDetailPage;
use App\Filament\Pages\PlatformFirmIntegrationsPage;
use App\Filament\Pages\PlatformIntegrationOverviewPage;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationConflict;
use App\Integrations\Models\IntegrationOutboxEvent;
use App\Integrations\Models\IntegrationSyncItem;
use App\Integrations\Models\IntegrationSyncRun;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\SupportAccessRequest;
use App\Models\SupportAccessSession;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\AssertsUiStateHasNoSecrets;
use Tests\TestCase;

/**
 * PlatformIntegrationAdminUiSecretSafetyTest — Checkpoint 11 (frozen-
 * design-post-security-review.md §9 discipline extended to the new
 * SuperAdmin admin-panel surface). Reuses Checkpoint 10's
 * AssertsUiStateHasNoSecrets helper verbatim (per this checkpoint's own
 * "do not reinvent" directive) — dual/triple-channel check (rendered
 * HTML, decoded wire:snapshot, Livewire in-memory snapshot) plus its
 * required negative control, applied to every new Checkpoint 11 page.
 * Also folds in the one negative structural export-mechanism assertion
 * frozen design §9/§13 assigns to this file (no dedicated export test
 * file was scaffolded — see frozen design §13's own "not scaffolded"
 * note).
 */
final class PlatformIntegrationAdminUiSecretSafetyTest extends TestCase
{
    use AssertsUiStateHasNoSecrets;
    use RefreshDatabase;

    private const WEBHOOK_TOKEN_MARKER = 'SECRET-MARKER-webhook-routing-token-9f3a7b1e2c6d4a58';

    private const OUTBOX_LAST_ERROR_MARKER = 'SECRET-MARKER-outbox-last-error-4b7e9a2f1d8c3650';

    private const SYNC_ITEM_LAST_ERROR_MARKER = 'SECRET-MARKER-sync-item-last-error-7c2a5f8e1b9d4036';

    private const RESOLUTION_NOTE_MARKER = 'SECRET-MARKER-resolution-note-2a6c8e1f4b7d9053';

    private const LOCAL_VALUE_MARKER = 'SECRET-MARKER-local-value-8d1f3a5c7e9b2046';

    private const EXTERNAL_VALUE_MARKER = 'SECRET-MARKER-external-value-6e0c2b4d8f1a3957';

    // ------------------------------------------------------------
    // 0. Required negative control (frozen design §9)
    // ------------------------------------------------------------

    public function test_the_secret_marker_assertion_itself_fails_red_against_a_deliberate_leak(): void
    {
        $this->assertSecretMarkerAssertionActuallyFailsRedOnALeak('this-marker-must-be-caught-checkpoint-11-1a2b3c4d');
    }

    // ------------------------------------------------------------
    // 1. Overview page
    // ------------------------------------------------------------

    public function test_the_overview_page_never_leaks_any_planted_marker(): void
    {
        $firm = Firm::factory()->activated()->create();
        DB::table('integration_platform_overview_summaries')->insert([
            'firm_id' => $firm->id,
            'firm_uuid' => $firm->uuid,
            'connection_count_active' => 1,
            'connection_count_disconnected' => 0,
            'connection_count_other' => 0,
            'health_summary_state' => null,
            'last_sync_outcome' => null,
            'last_sync_at' => null,
            'failed_permanent_sync_item_count' => 0,
            'dead_lettered_outbox_event_count' => 0,
            'open_conflict_count' => 0,
            'entitlement_enabled' => false,
            'computed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformIntegrationOverviewPage::class);
        $test->assertOk();

        $this->assertUiStateHasNoSecretMarker($test, self::WEBHOOK_TOKEN_MARKER, 'PlatformIntegrationOverviewPage');
    }

    // ------------------------------------------------------------
    // 2. Firm integrations list page
    // ------------------------------------------------------------

    public function test_the_firm_integrations_page_never_leaks_the_webhook_routing_token_marker(): void
    {
        [$firm] = $this->fixtureWithAllMarkersPlanted();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformFirmIntegrationsPage::class, ['firmUuid' => $firm->uuid]);
        $test->assertOk();

        $this->assertUiStateHasNoSecretMarker($test, self::WEBHOOK_TOKEN_MARKER, 'PlatformFirmIntegrationsPage');
    }

    // ------------------------------------------------------------
    // 3. Connection detail page — every prohibited field
    // ------------------------------------------------------------

    public function test_the_detail_page_never_leaks_the_webhook_routing_token_marker(): void
    {
        [$firm, $connection] = $this->fixtureWithAllMarkersPlanted();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->activeSessionFor($admin, $firm);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformFirmIntegrationDetailPage::class, [
            'firmUuid' => $firm->uuid,
            'connectionUuid' => $connection->uuid,
        ]);
        $test->assertOk();

        $this->assertUiStateHasNoSecretMarker($test, self::WEBHOOK_TOKEN_MARKER, 'PlatformFirmIntegrationDetailPage (webhook token)');
    }

    public function test_the_detail_page_never_leaks_the_outbox_last_error_marker(): void
    {
        [$firm, $connection] = $this->fixtureWithAllMarkersPlanted();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformFirmIntegrationDetailPage::class, [
            'firmUuid' => $firm->uuid,
            'connectionUuid' => $connection->uuid,
        ]);
        $test->assertOk();

        $this->assertUiStateHasNoSecretMarker($test, self::OUTBOX_LAST_ERROR_MARKER, 'PlatformFirmIntegrationDetailPage (outbox last_error)');
    }

    public function test_the_detail_page_never_leaks_the_sync_item_last_error_marker(): void
    {
        [$firm, $connection] = $this->fixtureWithAllMarkersPlanted();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformFirmIntegrationDetailPage::class, [
            'firmUuid' => $firm->uuid,
            'connectionUuid' => $connection->uuid,
        ]);
        $test->assertOk();

        $this->assertUiStateHasNoSecretMarker($test, self::SYNC_ITEM_LAST_ERROR_MARKER, 'PlatformFirmIntegrationDetailPage (sync item last_error)');
    }

    public function test_the_detail_page_never_leaks_the_resolution_note_marker_without_an_active_session(): void
    {
        [$firm, $connection] = $this->fixtureWithAllMarkersPlanted();
        // Ceiling role, but deliberately NO active support-access session.
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformFirmIntegrationDetailPage::class, [
            'firmUuid' => $firm->uuid,
            'connectionUuid' => $connection->uuid,
        ]);
        $test->assertOk();

        $this->assertUiStateHasNoSecretMarker($test, self::RESOLUTION_NOTE_MARKER, 'PlatformFirmIntegrationDetailPage (resolution_note, no active session)');
    }

    public function test_the_detail_page_never_leaks_local_value_or_external_value_markers_even_with_an_active_session(): void
    {
        [$firm, $connection] = $this->fixtureWithAllMarkersPlanted();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->activeSessionFor($admin, $firm);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformFirmIntegrationDetailPage::class, [
            'firmUuid' => $firm->uuid,
            'connectionUuid' => $connection->uuid,
        ]);
        $test->assertOk();

        $this->assertUiStateHasNoSecretMarker($test, self::LOCAL_VALUE_MARKER, 'PlatformFirmIntegrationDetailPage (local_value, active session)');
        $this->assertUiStateHasNoSecretMarker($test, self::EXTERNAL_VALUE_MARKER, 'PlatformFirmIntegrationDetailPage (external_value, active session)');
    }

    public function test_external_account_id_is_masked_never_shown_raw(): void
    {
        $firm = Firm::factory()->activated()->create();
        $rawMarker = 'RAW-ACCT-ID-MARKER-1234567890abcdef';
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create([
            'external_account_id' => $rawMarker,
        ]));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(PlatformFirmIntegrationDetailPage::class, [
            'firmUuid' => $firm->uuid,
            'connectionUuid' => $connection->uuid,
        ]);
        $test->assertOk();

        $this->assertUiStateHasNoSecretMarker($test, $rawMarker, 'PlatformFirmIntegrationDetailPage (raw external_account_id)');
        // The masked last-4-characters form IS expected to render.
        $test->assertSee(substr($rawMarker, -4));
    }

    // ------------------------------------------------------------
    // Structural: no export mechanism anywhere in the new admin surface
    // (frozen design §9/§13's folded negative assertion).
    // ------------------------------------------------------------

    public function test_no_export_mechanism_exists_anywhere_in_the_checkpoint_11_admin_surface(): void
    {
        $dirs = [
            app_path('Filament/Pages'),
            app_path('Filament/Actions/Platform'),
        ];

        $needles = ['Exporter', 'ExportAction', 'ExportColumn', 'ExportBulkAction'];

        foreach ($dirs as $dir) {
            $this->assertTrue(is_dir($dir), "{$dir} must exist.");

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $fileInfo) {
                if ($fileInfo->getExtension() !== 'php') {
                    continue;
                }

                $source = file_get_contents($fileInfo->getPathname());
                $this->assertIsString($source);

                foreach ($needles as $needle) {
                    $this->assertStringNotContainsString($needle, $source, "{$fileInfo->getPathname()} must not reference {$needle}.");
                }
            }
        }
    }

    // ------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------

    /**
     * @return array{0: Firm, 1: FirmIntegration}
     */
    private function fixtureWithAllMarkersPlanted(): array
    {
        $firm = Firm::factory()->activated()->create();

        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create([
            'webhook_routing_token' => self::WEBHOOK_TOKEN_MARKER,
        ]));

        $this->runWithFirmContext($firm, function () use ($connection) {
            IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->deadLettered()->create([
                'last_error' => PlatformIntegrationAdminUiSecretSafetyTest::OUTBOX_LAST_ERROR_MARKER,
            ]);

            $run = IntegrationSyncRun::factory()->forFirmIntegration($connection)->succeeded()->create();
            IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create([
                'last_error' => PlatformIntegrationAdminUiSecretSafetyTest::SYNC_ITEM_LAST_ERROR_MARKER,
            ]);

            IntegrationConflict::factory()->forFirmIntegration($connection)->create([
                'resolution_note' => PlatformIntegrationAdminUiSecretSafetyTest::RESOLUTION_NOTE_MARKER,
                'local_value' => ['confidential' => PlatformIntegrationAdminUiSecretSafetyTest::LOCAL_VALUE_MARKER],
                'external_value' => ['confidential' => PlatformIntegrationAdminUiSecretSafetyTest::EXTERNAL_VALUE_MARKER],
            ]);
        });

        return [$firm, $connection];
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    private function activeSessionFor(PlatformAdmin $admin, Firm $firm): void
    {
        $request = $this->runWithFirmContext(
            $firm,
            fn () => SupportAccessRequest::factory()->forFirm($firm)->create(['requested_by' => $admin->id])
        );

        $this->runWithFirmContext($firm, fn () => SupportAccessSession::factory()->create([
            'firm_id' => $firm->id,
            'support_access_request_id' => $request->id,
            'platform_admin_id' => $admin->id,
            'status' => SupportAccessSessionStatus::Active->value,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
        ]));
    }
}
