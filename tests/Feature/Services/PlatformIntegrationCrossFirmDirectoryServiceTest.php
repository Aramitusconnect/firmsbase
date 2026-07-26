<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\PlatformRoleCode;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationConflict;
use App\Integrations\Models\IntegrationInboundWebhookEvent;
use App\Integrations\Models\IntegrationOutboxEvent;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Models\IntegrationSyncItem;
use App\Integrations\Models\IntegrationSyncRun;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformIntegrationCrossFirmDirectoryService;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PlatformIntegrationCrossFirmDirectoryServiceTest — Phase 2 (FirmsVault
 * Platform Admin Control Center, "Integration Operations Center").
 * Proves the per-firm loop + merge pattern this service uses (mirroring
 * PlatformFirmUserDirectoryServiceTest's own established shape) for each
 * of the four cross-firm lists, plus the load-bearing redaction and
 * deterministic-ordering guarantees.
 */
final class PlatformIntegrationCrossFirmDirectoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private const LAST_ERROR_MARKER = 'SECRET-MARKER-last-error-cross-firm-9a1c';

    private PlatformIntegrationCrossFirmDirectoryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PlatformIntegrationCrossFirmDirectoryService::class);
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    private function connectionForFirm(Firm $firm): FirmIntegration
    {
        return $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
    }

    // ------------------------------------------------------------
    // Sync Failures
    // ------------------------------------------------------------

    public function test_list_sync_failures_merges_across_every_firm_and_excludes_non_failed_items(): void
    {
        $firmA = Firm::factory()->activated()->create(['name' => 'Firm A']);
        $firmB = Firm::factory()->activated()->create(['name' => 'Firm B']);
        $connA = $this->connectionForFirm($firmA);
        $connB = $this->connectionForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($connA): void {
            $run = IntegrationSyncRun::factory()->forFirmIntegration($connA)->succeeded()->create();
            IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create();
            IntegrationSyncItem::factory()->forSyncRun($run)->succeeded()->create();
        });

        $this->runWithFirmContext($firmB, function () use ($connB): void {
            $run = IntegrationSyncRun::factory()->forFirmIntegration($connB)->succeeded()->create();
            IntegrationSyncItem::factory()->forSyncRun($run)->failedRetryable()->create();
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $rows = $this->service->listSyncFailures($admin);

        $this->assertCount(2, $rows, 'Only the two failed items across both firms should appear — the succeeded item must be excluded.');
        $this->assertCount(1, $rows->where('firm_name', 'Firm A'));
        $this->assertCount(1, $rows->where('firm_name', 'Firm B'));
    }

    public function test_list_sync_failures_can_be_narrowed_to_a_single_firm(): void
    {
        $firmA = Firm::factory()->activated()->create();
        $firmB = Firm::factory()->activated()->create();
        $connA = $this->connectionForFirm($firmA);
        $connB = $this->connectionForFirm($firmB);

        $this->runWithFirmContext($firmA, function () use ($connA): void {
            $run = IntegrationSyncRun::factory()->forFirmIntegration($connA)->succeeded()->create();
            IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create();
        });
        $this->runWithFirmContext($firmB, function () use ($connB): void {
            $run = IntegrationSyncRun::factory()->forFirmIntegration($connB)->succeeded()->create();
            IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create();
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $rows = $this->service->listSyncFailures($admin, ['firm_uuid' => $firmA->uuid]);

        $this->assertCount(1, $rows);
        $this->assertSame($firmA->uuid, $rows->first()['firm_uuid']);
    }

    public function test_list_sync_failures_status_filter_is_applied(): void
    {
        $firm = Firm::factory()->activated()->create();
        $conn = $this->connectionForFirm($firm);

        $this->runWithFirmContext($firm, function () use ($conn): void {
            $run = IntegrationSyncRun::factory()->forFirmIntegration($conn)->succeeded()->create();
            IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create();
            IntegrationSyncItem::factory()->forSyncRun($run)->failedRetryable()->create();
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $rows = $this->service->listSyncFailures($admin, ['status' => 'failed_permanent']);

        $this->assertCount(1, $rows);
        $this->assertSame('failed_permanent', $rows->first()['status']);
    }

    public function test_last_error_never_appears_anywhere_in_sync_failure_rows(): void
    {
        $firm = Firm::factory()->activated()->create();
        $conn = $this->connectionForFirm($firm);

        $this->runWithFirmContext($firm, function () use ($conn): void {
            $run = IntegrationSyncRun::factory()->forFirmIntegration($conn)->succeeded()->create();
            IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create(['last_error' => self::LAST_ERROR_MARKER]);
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $rows = $this->service->listSyncFailures($admin);

        $json = json_encode($rows->all());
        $this->assertStringNotContainsString(self::LAST_ERROR_MARKER, $json);
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('last_error', $row);
        }
    }

    public function test_find_sync_failure_does_not_resolve_under_the_wrong_firm(): void
    {
        $firmA = Firm::factory()->activated()->create();
        $firmB = Firm::factory()->activated()->create();
        $connA = $this->connectionForFirm($firmA);

        $item = $this->runWithFirmContext($firmA, function () use ($connA): IntegrationSyncItem {
            $run = IntegrationSyncRun::factory()->forFirmIntegration($connA)->succeeded()->create();

            return IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create();
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->assertNotNull($this->service->findSyncFailure($admin, $firmA, $item->id));
        $this->assertNull($this->service->findSyncFailure($admin, $firmB, $item->id));
    }

    public function test_equal_timestamp_rows_are_ordered_deterministically_by_id(): void
    {
        $firm = Firm::factory()->activated()->create();
        $conn = $this->connectionForFirm($firm);
        $sameInstant = now();

        [$first, $second] = $this->runWithFirmContext($firm, function () use ($conn, $sameInstant): array {
            $run = IntegrationSyncRun::factory()->forFirmIntegration($conn)->succeeded()->create();
            $first = IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create();
            $second = IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create();
            $first->forceFill(['updated_at' => $sameInstant])->saveQuietly();
            $second->forceFill(['updated_at' => $sameInstant])->saveQuietly();

            return [$first, $second];
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $rows = $this->service->listSyncFailures($admin)->values();

        // Descending id tie-break — the higher id (created later) sorts first.
        $this->assertSame($second->id, $rows[0]['id']);
        $this->assertSame($first->id, $rows[1]['id']);
    }

    public function test_a_role_without_integration_oversight_access_sees_no_firms(): void
    {
        $firm = Firm::factory()->activated()->create();
        $conn = $this->connectionForFirm($firm);
        $this->runWithFirmContext($firm, function () use ($conn): void {
            $run = IntegrationSyncRun::factory()->forFirmIntegration($conn)->succeeded()->create();
            IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create();
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);
        $rows = $this->service->listSyncFailures($admin);

        $this->assertCount(0, $rows, 'A role that fails the coarse oversight gate must be skipped per firm, not throw.');
    }

    // ------------------------------------------------------------
    // Webhook Events
    // ------------------------------------------------------------

    public function test_list_webhook_events_merges_across_every_firm(): void
    {
        $firmA = Firm::factory()->activated()->create(['name' => 'Firm A']);
        $firmB = Firm::factory()->activated()->create(['name' => 'Firm B']);
        $connA = $this->connectionForFirm($firmA);
        $connB = $this->connectionForFirm($firmB);

        $this->runWithFirmContext($firmA, fn () => IntegrationInboundWebhookEvent::factory()->forFirmIntegration($connA)->create());
        $this->runWithFirmContext($firmB, function () use ($connB): void {
            IntegrationInboundWebhookEvent::factory()->forFirmIntegration($connB)->create();
            IntegrationInboundWebhookEvent::factory()->forFirmIntegration($connB)->processed()->create();
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $rows = $this->service->listWebhookEvents($admin);

        $this->assertCount(3, $rows);
        $this->assertCount(1, $rows->where('firm_name', 'Firm A'));
        $this->assertCount(2, $rows->where('firm_name', 'Firm B'));
    }

    public function test_webhook_event_payload_columns_never_appear_in_rows(): void
    {
        $firm = Firm::factory()->activated()->create();
        $conn = $this->connectionForFirm($firm);

        $this->runWithFirmContext($firm, fn () => IntegrationInboundWebhookEvent::factory()->forFirmIntegration($conn)->create([
            'payload_reference_json' => ['secret_marker' => self::LAST_ERROR_MARKER],
            'payload_hash' => 'deadbeef',
        ]));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $rows = $this->service->listWebhookEvents($admin);

        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertArrayNotHasKey('payload_reference_json', $row);
        $this->assertArrayNotHasKey('payload_hash', $row);
        $json = json_encode($rows->all());
        $this->assertStringNotContainsString(self::LAST_ERROR_MARKER, $json);
        $this->assertStringNotContainsString('deadbeef', $json);
    }

    public function test_webhook_event_status_filter_is_applied(): void
    {
        $firm = Firm::factory()->activated()->create();
        $conn = $this->connectionForFirm($firm);

        $this->runWithFirmContext($firm, function () use ($conn): void {
            IntegrationInboundWebhookEvent::factory()->forFirmIntegration($conn)->create();
            IntegrationInboundWebhookEvent::factory()->forFirmIntegration($conn)->processed()->create();
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $rows = $this->service->listWebhookEvents($admin, ['status' => 'processed']);

        $this->assertCount(1, $rows);
        $this->assertSame('processed', $rows->first()['status']);
    }

    public function test_find_webhook_event_does_not_resolve_under_the_wrong_firm(): void
    {
        $firmA = Firm::factory()->activated()->create();
        $firmB = Firm::factory()->activated()->create();
        $connA = $this->connectionForFirm($firmA);

        $event = $this->runWithFirmContext($firmA, fn () => IntegrationInboundWebhookEvent::factory()->forFirmIntegration($connA)->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->assertNotNull($this->service->findWebhookEvent($admin, $firmA, $event->id));
        $this->assertNull($this->service->findWebhookEvent($admin, $firmB, $event->id));
    }

    // ------------------------------------------------------------
    // Dead-Letter Queue
    // ------------------------------------------------------------

    public function test_list_dead_letter_queue_only_includes_dead_lettered_events(): void
    {
        $firm = Firm::factory()->activated()->create();
        $conn = $this->connectionForFirm($firm);

        $this->runWithFirmContext($firm, function () use ($conn): void {
            IntegrationOutboxEvent::factory()->forFirmIntegration($conn)->deadLettered()->create();
            IntegrationOutboxEvent::factory()->forFirmIntegration($conn)->completed()->create();
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $rows = $this->service->listDeadLetterQueue($admin);

        $this->assertCount(1, $rows);
    }

    public function test_dead_letter_queue_last_error_never_appears_in_rows(): void
    {
        $firm = Firm::factory()->activated()->create();
        $conn = $this->connectionForFirm($firm);

        $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($conn)->deadLettered()->create([
            'last_error' => self::LAST_ERROR_MARKER,
        ]));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $rows = $this->service->listDeadLetterQueue($admin);

        $json = json_encode($rows->all());
        $this->assertStringNotContainsString(self::LAST_ERROR_MARKER, $json);
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('last_error', $row);
        }
    }

    public function test_find_dead_letter_event_does_not_resolve_under_the_wrong_firm(): void
    {
        $firmA = Firm::factory()->activated()->create();
        $firmB = Firm::factory()->activated()->create();
        $connA = $this->connectionForFirm($firmA);

        $event = $this->runWithFirmContext($firmA, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connA)->deadLettered()->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->assertNotNull($this->service->findDeadLetterEvent($admin, $firmA, $event->id));
        $this->assertNull($this->service->findDeadLetterEvent($admin, $firmB, $event->id));
    }

    // ------------------------------------------------------------
    // Conflicts
    // ------------------------------------------------------------

    public function test_list_conflicts_merges_across_every_firm_and_never_exposes_raw_values(): void
    {
        $firmA = Firm::factory()->activated()->create(['name' => 'Firm A']);
        $firmB = Firm::factory()->activated()->create(['name' => 'Firm B']);
        $connA = $this->connectionForFirm($firmA);
        $connB = $this->connectionForFirm($firmB);

        $this->runWithFirmContext($firmA, fn () => IntegrationConflict::factory()->forFirmIntegration($connA)->create([
            'local_value' => ['secret' => self::LAST_ERROR_MARKER],
            'external_value' => ['secret' => self::LAST_ERROR_MARKER],
        ]));
        $this->runWithFirmContext($firmB, fn () => IntegrationConflict::factory()->forFirmIntegration($connB)->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $rows = $this->service->listConflicts($admin);

        $this->assertCount(2, $rows);
        $json = json_encode($rows->all());
        $this->assertStringNotContainsString(self::LAST_ERROR_MARKER, $json);
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('local_value', $row);
            $this->assertArrayNotHasKey('external_value', $row);
            $this->assertArrayNotHasKey('resolution_note', $row);
        }
    }

    public function test_find_conflict_does_not_resolve_under_the_wrong_firm(): void
    {
        $firmA = Firm::factory()->activated()->create();
        $firmB = Firm::factory()->activated()->create();
        $connA = $this->connectionForFirm($firmA);

        $conflict = $this->runWithFirmContext($firmA, fn () => IntegrationConflict::factory()->forFirmIntegration($connA)->create());

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->assertNotNull($this->service->findConflict($admin, $firmA, $conflict->id));
        $this->assertNull($this->service->findConflict($admin, $firmB, $conflict->id));
    }

    public function test_conflicts_provider_filter_is_applied(): void
    {
        $firm = Firm::factory()->activated()->create();
        $providerA = IntegrationProvider::factory()->create(['code' => 'provider-a-'.uniqid()]);
        $providerB = IntegrationProvider::factory()->create(['code' => 'provider-b-'.uniqid()]);
        $connA = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create(['integration_provider_id' => $providerA->id]));
        $connB = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create(['integration_provider_id' => $providerB->id]));

        $this->runWithFirmContext($firm, function () use ($connA, $connB): void {
            IntegrationConflict::factory()->forFirmIntegration($connA)->create();
            IntegrationConflict::factory()->forFirmIntegration($connB)->create();
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $rows = $this->service->listConflicts($admin, ['provider_code' => $providerA->code]);

        $this->assertCount(1, $rows);
        $this->assertSame($providerA->code, $rows->first()['provider_code']);
    }
}
