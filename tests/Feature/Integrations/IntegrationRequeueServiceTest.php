<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\OutboxEventStatus;
use App\Integrations\Enums\SyncItemStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Models\IntegrationOutboxEvent;
use App\Integrations\Models\IntegrationSyncItem;
use App\Integrations\Models\IntegrationSyncRun;
use App\Integrations\Services\IntegrationOutboxEventService;
use App\Integrations\Services\IntegrationRequeueAuditLogger;
use App\Integrations\Services\SyncItemService;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * IntegrationRequeueServiceTest — Checkpoint 9 (frozen design §7;
 * agent-9e-requeue-governance.md). Covers BOTH
 * IntegrationOutboxEventService::requeue() and
 * SyncItemService::requeueFromFailedPermanent(): successful requeue
 * from the correct terminal state; rejection from every wrong state;
 * rejection when superseded; rejection when connection disconnected;
 * rejection when credential revoked; cross-firm rejection;
 * attempts/attempt_count never decremented; max_attempts/max_requeues
 * ceiling enforcement (the diff review's flagged
 * `requeue_count < max_requeues` guard); duplicate/idempotent requeue
 * calls don't double-fire.
 */
class IntegrationRequeueServiceTest extends TestCase
{
    use RefreshDatabase;

    private IntegrationOutboxEventService $outboxService;

    private SyncItemService $syncItemService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outboxService = app(IntegrationOutboxEventService::class);
        $this->syncItemService = app(SyncItemService::class);
    }

    private function connection(Firm $firm): FirmIntegration
    {
        return $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
    }

    private function activeCredential(Firm $firm, FirmIntegration $connection): IntegrationCredential
    {
        return $this->createWithFirmContext($firm, fn () => IntegrationCredential::factory()->forFirmIntegration($connection)->create());
    }

    // ==============================================================
    // Part 1: IntegrationOutboxEventService::requeue()
    // ==============================================================

    public function test_outbox_successful_requeue_from_dead_lettered_transitions_to_pending_and_clears_the_lock(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->activeCredential($firm, $connection);
        $event = $this->createWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()
            ->forFirmIntegration($connection)->deadLettered()->create(['attempts' => 10, 'max_attempts' => 10]));

        $requeued = $this->runWithFirmContext($firm, fn () => $this->outboxService->requeue($event->id, $firm->id, 'manual_retry'));

        $this->assertNotNull($requeued);
        $this->assertSame(OutboxEventStatus::Pending, $requeued->status);
        $this->assertNull($requeued->lock_token);
        $this->assertNull($requeued->locked_at);
        $this->assertNotNull($requeued->requeued_at);
    }

    public function test_outbox_requeue_is_rejected_from_every_wrong_status(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->activeCredential($firm, $connection);

        $statuses = [
            'pending' => fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->create(),
            'processing' => fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->processing()->create(),
            'completed' => fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->completed()->create(),
            'cancelled' => fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->create(['status' => OutboxEventStatus::Cancelled->value]),
        ];

        foreach ($statuses as $label => $factory) {
            $event = $this->createWithFirmContext($firm, $factory);

            $result = $this->runWithFirmContext($firm, fn () => $this->outboxService->requeue($event->id, $firm->id, 'manual_retry'));

            $this->assertNull($result, "requeue() must reject an event in status '{$label}'");
        }
    }

    public function test_outbox_requeue_is_rejected_when_superseded_by_a_newer_completed_event_for_the_same_logical_operation(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->activeCredential($firm, $connection);

        $old = $this->createWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()
            ->forFirmIntegration($connection)
            ->deadLettered()
            ->create(['resource_type' => 'contact', 'resource_id' => '1', 'created_at' => now()->subHour()]));

        // A newer event for the SAME (firm_integration_id, resource_type,
        // resource_id) already reached 'completed' — supersedes the old one.
        $this->createWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()
            ->forFirmIntegration($connection)
            ->completed()
            ->create(['resource_type' => 'contact', 'resource_id' => '1', 'created_at' => now()]));

        $result = $this->runWithFirmContext($firm, fn () => $this->outboxService->requeue($old->id, $firm->id, 'manual_retry'));

        $this->assertNull($result, 'A dead-lettered event superseded by a newer non-terminal-or-completed event for the same logical operation must never be requeued.');
    }

    public function test_outbox_requeue_is_rejected_when_the_connection_is_disconnected(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->activeCredential($firm, $connection);
        $event = $this->createWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->deadLettered()->create());

        $this->runWithFirmContext($firm, fn () => DB::table('firm_integrations')->where('id', $connection->id)->update(['status' => ConnectionStatus::Disconnected->value]));

        $result = $this->runWithFirmContext($firm, fn () => $this->outboxService->requeue($event->id, $firm->id, 'manual_retry'));

        $this->assertNull($result);
    }

    public function test_outbox_requeue_is_rejected_when_the_credential_is_revoked(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $credential = $this->activeCredential($firm, $connection);
        $event = $this->createWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->deadLettered()->create());

        $this->runWithFirmContext($firm, fn () => DB::table('integration_credentials')->where('id', $credential->id)->update(['status' => 'revoked']));

        $result = $this->runWithFirmContext($firm, fn () => $this->outboxService->requeue($event->id, $firm->id, 'manual_retry'));

        $this->assertNull($result);
    }

    public function test_outbox_requeue_is_rejected_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionB = $this->connection($firmB);
        $this->activeCredential($firmB, $connectionB);
        $eventB = $this->createWithFirmContext($firmB, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connectionB)->deadLettered()->create());

        $result = $this->runWithFirmContext($firmA, fn () => $this->outboxService->requeue($eventB->id, $firmA->id, 'manual_retry'));

        $this->assertNull($result, 'requeue() must reject a cross-firm id/firm_id pairing.');
    }

    public function test_outbox_requeue_never_decrements_attempts_and_raises_max_attempts_by_the_fixed_increment(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->activeCredential($firm, $connection);
        $event = $this->createWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()
            ->forFirmIntegration($connection)->deadLettered()->create(['attempts' => 10, 'max_attempts' => 10]));

        $requeued = $this->runWithFirmContext($firm, fn () => $this->outboxService->requeue($event->id, $firm->id, 'manual_retry'));

        $this->assertNotNull($requeued);
        $this->assertSame(10, $requeued->attempts, 'attempts must NEVER be reset/decremented by requeue().');
        $this->assertSame(13, $requeued->max_attempts, 'max_attempts must be raised by the fixed +3 increment.');
    }

    public function test_outbox_max_requeues_ceiling_is_enforced(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->activeCredential($firm, $connection);

        // Already at the ceiling (requeue_count == max_requeues) while
        // otherwise perfectly eligible (dead_lettered, not superseded,
        // connection active, credential active).
        $event = $this->createWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()
            ->forFirmIntegration($connection)->deadLettered()->create(['requeue_count' => 3, 'max_requeues' => 3]));

        $result = $this->runWithFirmContext($firm, fn () => $this->outboxService->requeue($event->id, $firm->id, 'manual_retry'));

        $this->assertNull($result, 'requeue() must refuse once requeue_count has reached max_requeues, even though every other guard would pass.');
    }

    public function test_outbox_requeue_below_the_ceiling_succeeds_and_increments_requeue_count(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->activeCredential($firm, $connection);
        $event = $this->createWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()
            ->forFirmIntegration($connection)->deadLettered()->create(['requeue_count' => 2, 'max_requeues' => 3]));

        $requeued = $this->runWithFirmContext($firm, fn () => $this->outboxService->requeue($event->id, $firm->id, 'manual_retry'));

        $this->assertNotNull($requeued);
        $this->assertSame(3, $requeued->requeue_count);
    }

    public function test_outbox_duplicate_requeue_calls_do_not_double_fire(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->activeCredential($firm, $connection);
        $event = $this->createWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->deadLettered()->create());

        $logPath = storage_path('logs/integration-requeue-audit.log');
        $linesBefore = file_exists($logPath) ? count(file($logPath)) : 0;

        $first = $this->runWithFirmContext($firm, fn () => $this->outboxService->requeue($event->id, $firm->id, 'manual_retry'));
        $second = $this->runWithFirmContext($firm, fn () => $this->outboxService->requeue($event->id, $firm->id, 'manual_retry'));

        $this->assertNotNull($first);
        $this->assertNull($second, 'A second, duplicate requeue call against an already-requeued (now pending) row must return null, never re-fire.');

        $this->assertFileExists($logPath);
        $newLines = array_slice(file($logPath), $linesBefore);
        $occurrences = 0;
        foreach ($newLines as $line) {
            if (str_contains($line, IntegrationRequeueAuditLogger::EVENT_OUTBOX_EVENT_REQUEUED)) {
                $occurrences++;
            }
        }
        $this->assertSame(1, $occurrences, 'The audit logger must only record the ONE genuine requeue, never the losing duplicate call.');
    }

    // ==============================================================
    // Part 2: SyncItemService::requeueFromFailedPermanent()
    // ==============================================================

    private function syncRun(Firm $firm, FirmIntegration $connection): IntegrationSyncRun
    {
        return $this->createWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration($connection)->create());
    }

    public function test_sync_item_successful_requeue_from_failed_permanent_transitions_to_failed_retryable(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->activeCredential($firm, $connection);
        $run = $this->syncRun($firm, $connection);
        $item = $this->createWithFirmContext($firm, fn () => IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create());

        $requeued = $this->runWithFirmContext($firm, fn () => $this->syncItemService->requeueFromFailedPermanent($item->id, $firm->id, 'manual_retry'));

        $this->assertNotNull($requeued);
        $this->assertSame(SyncItemStatus::FailedRetryable, $requeued->status);
        $this->assertNull($requeued->terminal_at, 'terminal_at must be actively cleared so a future retention sweep never treats this row as still terminal.');
        $this->assertNotNull($requeued->requeued_at);
    }

    public function test_sync_item_requeue_is_rejected_from_every_wrong_status(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->activeCredential($firm, $connection);
        $run = $this->syncRun($firm, $connection);

        $statuses = [
            'pending' => fn () => IntegrationSyncItem::factory()->forSyncRun($run)->create(),
            'retrying' => fn () => IntegrationSyncItem::factory()->forSyncRun($run)->create(['status' => SyncItemStatus::Retrying->value]),
            'succeeded' => fn () => IntegrationSyncItem::factory()->forSyncRun($run)->succeeded()->create(),
            'failed_retryable' => fn () => IntegrationSyncItem::factory()->forSyncRun($run)->failedRetryable()->create(),
            'skipped' => fn () => IntegrationSyncItem::factory()->forSyncRun($run)->create(['status' => SyncItemStatus::Skipped->value, 'terminal_at' => now()]),
        ];

        foreach ($statuses as $label => $factory) {
            $item = $this->createWithFirmContext($firm, $factory);

            $result = $this->runWithFirmContext($firm, fn () => $this->syncItemService->requeueFromFailedPermanent($item->id, $firm->id, 'manual_retry'));

            $this->assertNull($result, "requeueFromFailedPermanent() must reject an item in status '{$label}'");
        }
    }

    public function test_sync_item_requeue_is_rejected_when_superseded_by_a_later_runs_succeeded_item_for_the_same_external_id(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->activeCredential($firm, $connection);

        $oldRun = $this->syncRun($firm, $connection);
        $oldItem = $this->createWithFirmContext($firm, fn () => IntegrationSyncItem::factory()
            ->forSyncRun($oldRun)->failedPermanent()->create(['external_id' => 'ext-shared']));

        // A LATER run's item for the SAME external_id already succeeded.
        // The newer run itself must be in a TERMINAL status (succeeded())
        // — otherwise it collides with integration_sync_runs_one_active_per_scope's
        // partial unique index, which only covers pending/running rows
        // for the same (firm_integration_id, resource_type, direction) scope.
        $newerRun = $this->createWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration($connection)->succeeded()->create(['created_at' => now()->addMinute()]));
        $this->createWithFirmContext($firm, fn () => IntegrationSyncItem::factory()
            ->forSyncRun($newerRun)->succeeded()->create(['external_id' => 'ext-shared']));

        $result = $this->runWithFirmContext($firm, fn () => $this->syncItemService->requeueFromFailedPermanent($oldItem->id, $firm->id, 'manual_retry'));

        $this->assertNull($result, 'An item superseded by a later run\'s already-succeeded item for the same external_id must never be requeued.');
    }

    public function test_sync_item_requeue_is_rejected_when_the_connection_is_disconnected(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->activeCredential($firm, $connection);
        $run = $this->syncRun($firm, $connection);
        $item = $this->createWithFirmContext($firm, fn () => IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create());

        $this->runWithFirmContext($firm, fn () => DB::table('firm_integrations')->where('id', $connection->id)->update(['status' => ConnectionStatus::Disconnected->value]));

        $result = $this->runWithFirmContext($firm, fn () => $this->syncItemService->requeueFromFailedPermanent($item->id, $firm->id, 'manual_retry'));

        $this->assertNull($result);
    }

    public function test_sync_item_requeue_is_rejected_when_the_credential_is_revoked(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $credential = $this->activeCredential($firm, $connection);
        $run = $this->syncRun($firm, $connection);
        $item = $this->createWithFirmContext($firm, fn () => IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create());

        $this->runWithFirmContext($firm, fn () => DB::table('integration_credentials')->where('id', $credential->id)->update(['status' => 'revoked']));

        $result = $this->runWithFirmContext($firm, fn () => $this->syncItemService->requeueFromFailedPermanent($item->id, $firm->id, 'manual_retry'));

        $this->assertNull($result);
    }

    public function test_sync_item_requeue_is_rejected_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionB = $this->connection($firmB);
        $this->activeCredential($firmB, $connectionB);
        $runB = $this->syncRun($firmB, $connectionB);
        $itemB = $this->createWithFirmContext($firmB, fn () => IntegrationSyncItem::factory()->forSyncRun($runB)->failedPermanent()->create());

        $result = $this->runWithFirmContext($firmA, fn () => $this->syncItemService->requeueFromFailedPermanent($itemB->id, $firmA->id, 'manual_retry'));

        $this->assertNull($result);
    }

    public function test_sync_item_requeue_never_decrements_attempt_count_and_has_no_ceiling_column(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->activeCredential($firm, $connection);
        $run = $this->syncRun($firm, $connection);
        $item = $this->createWithFirmContext($firm, fn () => IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create(['attempt_count' => 5]));

        $requeued = $this->runWithFirmContext($firm, fn () => $this->syncItemService->requeueFromFailedPermanent($item->id, $firm->id, 'manual_retry'));

        $this->assertNotNull($requeued);
        $this->assertSame(5, $requeued->attempt_count, 'attempt_count must NEVER be reset by requeueFromFailedPermanent().');
        $this->assertNotContains('max_requeues', \Illuminate\Support\Facades\Schema::getColumnListing('integration_sync_items'), 'integration_sync_items has no max_requeues-equivalent column — eligibility is status-gated only.');
    }

    public function test_sync_item_requeue_can_be_repeated_many_times_since_there_is_no_ceiling_column(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->activeCredential($firm, $connection);
        $run = $this->syncRun($firm, $connection);
        $item = $this->createWithFirmContext($firm, fn () => IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create());

        $requeued = $this->runWithFirmContext($firm, fn () => $this->syncItemService->requeueFromFailedPermanent($item->id, $firm->id, 'manual_retry'));
        $this->assertSame(1, $requeued->requeue_count);

        // Bring it back to failed_permanent (simulating the retry poller
        // re-dead-lettering a structurally-blocked item) and requeue again.
        $this->runWithFirmContext($firm, fn () => DB::table('integration_sync_items')->where('id', $item->id)->update(['status' => SyncItemStatus::FailedPermanent->value]));
        $requeuedAgain = $this->runWithFirmContext($firm, fn () => $this->syncItemService->requeueFromFailedPermanent($item->id, $firm->id, 'manual_retry'));

        $this->assertNotNull($requeuedAgain);
        $this->assertSame(2, $requeuedAgain->requeue_count);
    }

    public function test_sync_item_duplicate_requeue_calls_do_not_double_fire(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->activeCredential($firm, $connection);
        $run = $this->syncRun($firm, $connection);
        $item = $this->createWithFirmContext($firm, fn () => IntegrationSyncItem::factory()->forSyncRun($run)->failedPermanent()->create());

        $logPath = storage_path('logs/integration-requeue-audit.log');
        $linesBefore = file_exists($logPath) ? count(file($logPath)) : 0;

        $first = $this->runWithFirmContext($firm, fn () => $this->syncItemService->requeueFromFailedPermanent($item->id, $firm->id, 'manual_retry'));
        $second = $this->runWithFirmContext($firm, fn () => $this->syncItemService->requeueFromFailedPermanent($item->id, $firm->id, 'manual_retry'));

        $this->assertNotNull($first);
        $this->assertNull($second);

        $this->assertFileExists($logPath);
        $newLines = array_slice(file($logPath), $linesBefore);
        $occurrences = 0;
        foreach ($newLines as $line) {
            if (str_contains($line, IntegrationRequeueAuditLogger::EVENT_SYNC_ITEM_REQUEUED)) {
                $occurrences++;
            }
        }
        $this->assertSame(1, $occurrences);
    }
}
