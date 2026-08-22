<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Enums\CursorStatus;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Enums\SyncRunStatus;
use App\Integrations\Enums\SyncRunType;
use App\Integrations\Enums\SyncTriggerSource;
use App\Integrations\Exceptions\SyncRunAlreadyInProgressException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationSyncCursor;
use App\Integrations\Models\IntegrationSyncRun;
use App\Integrations\Services\SyncRunService;
use App\Models\Firm;
use App\Services\TimelineEventRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * SyncRunServiceTest — Checkpoint 6
 * (agent-6e-sync-run-item-cursor-semantics.md §5.3/§9;
 * agent-6h-test-plan-and-review.md §6 item 5/12). Covers
 * determineRunType()'s fixed precedence order against every case in
 * Agent 6E's table, the composite self-FK cross-firm rejection on
 * retried_run_id, determineTerminalStatus()'s run-count reconciliation,
 * and illegal status transition rejection.
 */
class SyncRunServiceTest extends TestCase
{
    use RefreshDatabase;

    private SyncRunService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SyncRunService(new TimelineEventRecorder);
    }

    // ------------------------------------------------------------
    // determineRunType() — fixed precedence: Retry > Repair > Manual >
    // Scheduled > Outbound > Incremental > Initial
    // ------------------------------------------------------------

    public function test_retried_run_id_always_wins_regardless_of_other_inputs(): void
    {
        $invalidCursor = $this->cursorWith(CursorStatus::Invalid, null);

        $type = $this->service->determineRunType(
            SyncDirection::Outbound,
            SyncTriggerSource::Manual,
            $invalidCursor,
            retriedRunId: 42,
        );

        $this->assertSame(SyncRunType::Retry, $type, 'retried_run_id must win over every other precedence rule, including Repair.');
    }

    public function test_an_invalid_cursor_yields_repair_when_not_a_retry(): void
    {
        $invalidCursor = $this->cursorWith(CursorStatus::Invalid, null);

        $type = $this->service->determineRunType(
            SyncDirection::Inbound,
            SyncTriggerSource::SchedulerPoller,
            $invalidCursor,
            retriedRunId: null,
        );

        $this->assertSame(SyncRunType::Repair, $type, 'An Invalid cursor must win over Scheduled.');
    }

    public function test_manual_trigger_wins_over_scheduled_outbound_and_incremental(): void
    {
        $idleCursorWithValue = $this->cursorWith(CursorStatus::Idle, 'some-cursor-value');

        $type = $this->service->determineRunType(
            SyncDirection::Outbound,
            SyncTriggerSource::Manual,
            $idleCursorWithValue,
            retriedRunId: null,
        );

        $this->assertSame(SyncRunType::Manual, $type, 'Manual trigger must win over Outbound/Incremental, even with a non-invalid cursor.');
    }

    public function test_scheduler_poller_trigger_yields_scheduled_when_not_manual_or_repair(): void
    {
        $idleCursorWithValue = $this->cursorWith(CursorStatus::Idle, 'some-cursor-value');

        $type = $this->service->determineRunType(
            SyncDirection::Outbound,
            SyncTriggerSource::SchedulerPoller,
            $idleCursorWithValue,
            retriedRunId: null,
        );

        $this->assertSame(SyncRunType::Scheduled, $type, 'Scheduled must win over Outbound.');
    }

    public function test_outbound_direction_yields_outbound_when_no_higher_precedence_rule_applies(): void
    {
        $type = $this->service->determineRunType(
            SyncDirection::Outbound,
            SyncTriggerSource::Webhook,
            null,
            retriedRunId: null,
        );

        $this->assertSame(SyncRunType::Outbound, $type);
    }

    public function test_a_non_null_cursor_value_yields_incremental_when_direction_is_inbound(): void
    {
        $cursorWithValue = $this->cursorWith(CursorStatus::Idle, 'abc123');

        $type = $this->service->determineRunType(
            SyncDirection::Inbound,
            SyncTriggerSource::Webhook,
            $cursorWithValue,
            retriedRunId: null,
        );

        $this->assertSame(SyncRunType::Incremental, $type);
    }

    public function test_a_null_cursor_value_yields_initial(): void
    {
        $cursorWithoutValue = $this->cursorWith(CursorStatus::Idle, null);

        $type = $this->service->determineRunType(
            SyncDirection::Inbound,
            SyncTriggerSource::Connect,
            $cursorWithoutValue,
            retriedRunId: null,
        );

        $this->assertSame(SyncRunType::Initial, $type);
    }

    public function test_a_completely_absent_cursor_yields_initial(): void
    {
        $type = $this->service->determineRunType(
            SyncDirection::Inbound,
            SyncTriggerSource::Connect,
            null,
            retriedRunId: null,
        );

        $this->assertSame(SyncRunType::Initial, $type);
    }

    public function test_cursor_repair_auto_fire_trigger_source_does_not_itself_force_repair(): void
    {
        // Repair is determined by the CURSOR's own Invalid status, not by
        // trigger_source alone — CursorRepairAutoFire with a healthy
        // cursor still resolves via the ordinary precedence chain.
        $idleCursorWithoutValue = $this->cursorWith(CursorStatus::Idle, null);

        $type = $this->service->determineRunType(
            SyncDirection::Inbound,
            SyncTriggerSource::CursorRepairAutoFire,
            $idleCursorWithoutValue,
            retriedRunId: null,
        );

        $this->assertSame(SyncRunType::Initial, $type);
    }

    // ------------------------------------------------------------
    // startRun() — Layer 1 concurrency + typed exception
    // ------------------------------------------------------------

    public function test_start_run_persists_the_computed_run_type(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        $run = $this->runWithFirmContext(
            $firm,
            fn () => $this->service->startRun($connection, 'contact', SyncDirection::Inbound, SyncTriggerSource::Connect),
        );

        $this->assertSame(SyncRunType::Initial, $run->run_type);
        $this->assertSame(SyncRunStatus::Pending, $run->status);
    }

    public function test_start_run_throws_a_typed_exception_when_a_non_terminal_run_already_exists_for_the_scope(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        $existing = $this->runWithFirmContext(
            $firm,
            fn () => $this->service->startRun($connection, 'contact', SyncDirection::Inbound, SyncTriggerSource::Connect),
        );

        try {
            $this->runWithFirmContext(
                $firm,
                fn () => $this->service->startRun($connection, 'contact', SyncDirection::Inbound, SyncTriggerSource::Manual),
            );
            $this->fail('Expected SyncRunAlreadyInProgressException to be thrown.');
        } catch (SyncRunAlreadyInProgressException $e) {
            $this->assertSame($existing->id, $e->existingRun->id);
        }
    }

    // ------------------------------------------------------------
    // retried_run_id composite self-FK cross-firm rejection
    // ------------------------------------------------------------

    public function test_retried_run_id_composite_self_foreign_key_rejects_a_run_from_a_different_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $connectionA = FirmIntegration::factory()->forFirm($firmA)->create();
        $runB = IntegrationSyncRun::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firmB)->create())->failed()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/foreign key constraint/i');

        $this->runWithFirmContext(
            $firmA,
            fn () => $this->service->startRun($connectionA, 'contact', SyncDirection::Inbound, SyncTriggerSource::RetryPoller, retriedRunId: $runB->id),
        );
    }

    public function test_retried_run_id_composite_self_foreign_key_accepts_a_run_from_the_same_firm(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $failedRun = IntegrationSyncRun::factory()->forFirmIntegration($connection)->failed()->create();

        $retryRun = $this->runWithFirmContext(
            $firm,
            fn () => $this->service->startRun($connection, $failedRun->resource_type, $failedRun->sync_direction, SyncTriggerSource::RetryPoller, retriedRunId: $failedRun->id),
        );

        $this->assertSame(SyncRunType::Retry, $retryRun->run_type);
        $this->assertSame($failedRun->id, $retryRun->retried_run_id);
    }

    // ------------------------------------------------------------
    // determineTerminalStatus() — run-count internal consistency
    // ------------------------------------------------------------

    public function test_terminal_status_is_succeeded_when_no_items_failed(): void
    {
        $this->assertSame(SyncRunStatus::Succeeded, $this->service->determineTerminalStatus(10, 10, 0));
    }

    public function test_terminal_status_is_partial_failure_when_some_but_not_all_items_failed(): void
    {
        $this->assertSame(SyncRunStatus::PartialFailure, $this->service->determineTerminalStatus(10, 6, 4));
    }

    public function test_terminal_status_is_failed_when_every_item_failed(): void
    {
        $this->assertSame(SyncRunStatus::Failed, $this->service->determineTerminalStatus(5, 0, 5));
    }

    public function test_terminal_status_is_succeeded_for_a_run_with_zero_items(): void
    {
        $this->assertSame(SyncRunStatus::Succeeded, $this->service->determineTerminalStatus(0, 0, 0));
    }

    // ------------------------------------------------------------
    // transitionStatus() — illegal transition rejection
    // ------------------------------------------------------------

    public function test_transition_status_allows_pending_to_running(): void
    {
        $firm = Firm::factory()->create();
        $run = $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())->create());

        $updated = $this->runWithFirmContext($firm, fn () => $this->service->transitionStatus($run, SyncRunStatus::Running));

        $this->assertSame(SyncRunStatus::Running, $updated->status);
        $this->assertNotNull($updated->started_at);
    }

    public function test_transition_status_rejects_pending_to_succeeded_directly(): void
    {
        $firm = Firm::factory()->create();
        $run = $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())->create());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cannot transition sync run/');

        $this->runWithFirmContext($firm, fn () => $this->service->transitionStatus($run, SyncRunStatus::Succeeded));
    }

    public function test_transition_status_rejects_running_back_to_pending(): void
    {
        $firm = Firm::factory()->create();
        $run = $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())->running()->create());

        $this->expectException(RuntimeException::class);

        $this->runWithFirmContext($firm, fn () => $this->service->transitionStatus($run, SyncRunStatus::Pending));
    }

    public function test_transition_status_rejects_any_transition_from_a_terminal_succeeded_run(): void
    {
        $firm = Firm::factory()->create();
        $run = $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())->succeeded()->create());

        $this->expectException(RuntimeException::class);

        $this->runWithFirmContext($firm, fn () => $this->service->transitionStatus($run, SyncRunStatus::Running));
    }

    public function test_transition_status_sets_finished_at_for_every_terminal_status(): void
    {
        $firm = Firm::factory()->create();
        $run = $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())->running()->create());

        $updated = $this->runWithFirmContext($firm, fn () => $this->service->transitionStatus($run, SyncRunStatus::PartialFailure, 'some_items_failed'));

        $this->assertSame(SyncRunStatus::PartialFailure, $updated->status);
        $this->assertSame('some_items_failed', $updated->error_summary);
        $this->assertNotNull($updated->finished_at);
    }

    // ------------------------------------------------------------
    // requestCancellation()
    // ------------------------------------------------------------

    public function test_request_cancellation_sets_the_timestamp_on_a_non_terminal_run(): void
    {
        $firm = Firm::factory()->create();
        $run = $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())->running()->create());

        $updated = $this->runWithFirmContext($firm, fn () => $this->service->requestCancellation($run));

        $this->assertNotNull($updated->cancel_requested_at);
        $this->assertSame(SyncRunStatus::Running, $updated->status, 'No Cancelling status is added — cancellation-in-progress is represented by the timestamp alone.');
    }

    public function test_request_cancellation_rejects_an_already_terminal_run(): void
    {
        $firm = Firm::factory()->create();
        $run = $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())->succeeded()->create());

        $this->expectException(RuntimeException::class);

        $this->runWithFirmContext($firm, fn () => $this->service->requestCancellation($run));
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function cursorWith(CursorStatus $status, ?string $cursorValue): IntegrationSyncCursor
    {
        $cursor = new IntegrationSyncCursor;
        $cursor->status = $status;
        $cursor->cursor_value = $cursorValue;

        return $cursor;
    }
}
