<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationConflict;
use App\Integrations\Models\IntegrationInboundWebhookEvent;
use App\Integrations\Models\IntegrationOAuthState;
use App\Integrations\Models\IntegrationOutboxEvent;
use App\Integrations\Models\IntegrationSyncItem;
use App\Integrations\Models\IntegrationSyncRun;
use App\Integrations\Models\IntegrationUsageRecord;
use App\Integrations\Services\RetentionSweepAuditLogger;
use App\Jobs\RetentionSweepJob;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\RetentionGovernanceRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RetentionSweepJobTest — Checkpoint 8
 * (agent-8g-retention-cleanup-design.md;
 * agent-8h-architecture-security-review.md §1 items 7-9/§4.2). Proves
 * per-table eligibility across every retention target, the mandatory
 * NOT EXISTS cascade-hazard guard on sync runs, batch/resumability,
 * OAuth Class B's documented unconfigured no-op, and sanitized-only
 * audit logging.
 */
class RetentionSweepJobTest extends TestCase
{
    use RefreshDatabase;

    private function connection(Firm $firm): FirmIntegration
    {
        return $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
    }

    private function syncRun(Firm $firm, FirmIntegration $connection, string $status = 'succeeded'): int
    {
        $run = $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration($connection)->create(['status' => $status]));

        return $run->id;
    }

    private function sweep(Firm $firm, int $batchSize = 500): void
    {
        $job = new RetentionSweepJob($firm->id, false, $batchSize);
        $job->handle(new RetentionSweepAuditLogger());
    }

    // ------------------------------------------------------------
    // Outbox events — three independent terminal-status windows
    // ------------------------------------------------------------

    public function test_a_completed_outbox_event_past_its_retention_window_is_deleted(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $event = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()
            ->forFirmIntegration($connection)
            ->completed()
            ->create(['completed_at' => now()->subDays(35)]));

        $this->sweep($firm);

        $exists = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::query()->find($event->id));
        $this->assertNull($exists);
    }

    public function test_a_completed_outbox_event_within_its_retention_window_is_kept(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $event = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()
            ->forFirmIntegration($connection)
            ->completed()
            ->create(['completed_at' => now()->subDays(5)]));

        $this->sweep($firm);

        $exists = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::query()->find($event->id));
        $this->assertNotNull($exists);
    }

    public function test_a_dead_lettered_outbox_event_uses_its_own_ninety_day_window_not_the_completed_thirty_day_one(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        $recentlyDeadLettered = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()
            ->forFirmIntegration($connection)
            ->deadLettered()
            ->create(['dead_lettered_at' => now()->subDays(35)])); // > completed's 30d but < dead-lettered's 90d

        $this->sweep($firm);

        $exists = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::query()->find($recentlyDeadLettered->id));
        $this->assertNotNull($exists, 'A dead_lettered row 35 days old must NOT be swept — its own window is 90 days, not the completed branch\'s 30.');
    }

    public function test_a_dead_lettered_outbox_event_past_ninety_days_is_deleted(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $event = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()
            ->forFirmIntegration($connection)
            ->deadLettered()
            ->create(['dead_lettered_at' => now()->subDays(95)]));

        $this->sweep($firm);

        $exists = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::query()->find($event->id));
        $this->assertNull($exists);
    }

    public function test_a_pending_outbox_event_is_never_eligible_regardless_of_age(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $event = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()
            ->forFirmIntegration($connection)
            ->create(['next_attempt_at' => now()->subYear()]));

        $this->sweep($firm);

        $exists = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::query()->find($event->id));
        $this->assertNotNull($exists, 'A non-terminal (pending) row must never be swept regardless of how old it is.');
    }

    public function test_a_processing_outbox_event_is_never_eligible_regardless_of_age(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $event = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()
            ->forFirmIntegration($connection)
            ->processing()
            ->create(['locked_at' => now()->subYear()]));

        $this->sweep($firm);

        $exists = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::query()->find($event->id));
        $this->assertNotNull($exists);
    }

    // ------------------------------------------------------------
    // Sync items — 60 days from terminal_at
    // ------------------------------------------------------------

    public function test_a_sync_item_past_sixty_days_from_terminal_at_is_deleted(): void
    {
        // Checkpoint 13 P3 (DISABLE_BY_DEFAULT — agent-13h §3/§4 item 2):
        // the three firm-data sweeps (sync items, sync runs, resolved
        // conflicts) are now gated behind
        // `integrations.retention.sweep_firm_data_enabled`, which defaults
        // OFF. This test asserts the CORRECT, intentional behavior —
        // deletion DOES occur when a human has explicitly enabled the
        // kill-switch — so it must opt in exactly as this file's own
        // existing OAuth-state opt-in test
        // (test_an_unconsumed_expired_oauth_state_is_deleted_once_the_config_key_is_explicitly_set)
        // does for its own flag. The default-off skip behavior is proven
        // separately in RetentionSweepLegalHoldGuardTest.
        config(['integrations.retention.sweep_firm_data_enabled' => true]);

        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $runId = $this->syncRun($firm, $connection);
        $item = $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::factory()
            ->create(['firm_id' => $firm->id, 'sync_run_id' => $runId, 'status' => 'succeeded', 'terminal_at' => now()->subDays(70)]));

        $this->sweep($firm);

        $exists = $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::query()->find($item->id));
        $this->assertNull($exists);
    }

    public function test_a_sync_item_within_sixty_days_is_kept(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $runId = $this->syncRun($firm, $connection);
        $item = $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::factory()
            ->create(['firm_id' => $firm->id, 'sync_run_id' => $runId, 'status' => 'succeeded', 'terminal_at' => now()->subDays(10)]));

        $this->sweep($firm);

        $exists = $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::query()->find($item->id));
        $this->assertNotNull($exists);
    }

    public function test_a_non_terminal_sync_item_is_never_eligible_regardless_of_age(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $runId = $this->syncRun($firm, $connection);
        $item = $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::factory()
            ->create(['firm_id' => $firm->id, 'sync_run_id' => $runId, 'status' => 'pending', 'terminal_at' => null, 'updated_at' => now()->subYear()]));

        $this->sweep($firm);

        $exists = $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::query()->find($item->id));
        $this->assertNotNull($exists);
    }

    // ------------------------------------------------------------
    // Sync runs — 180 days from finished_at, GUARDED by the mandatory
    // NOT EXISTS cascade-hazard guard
    // ------------------------------------------------------------

    public function test_a_sync_run_past_its_window_with_no_remaining_child_items_is_deleted(): void
    {
        // Checkpoint 13 P3: firm-data sweep is default-off — opt in to
        // assert the intentional "deletion occurs when enabled" behavior
        // (see this file's sync-item variant above for the full rationale).
        config(['integrations.retention.sweep_firm_data_enabled' => true]);

        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $run = $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::factory()
            ->forFirmIntegration($connection)
            ->create(['status' => 'succeeded', 'finished_at' => now()->subDays(200)]));

        $this->sweep($firm);

        $exists = $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::query()->find($run->id));
        $this->assertNull($exists);
    }

    public function test_a_sync_run_past_its_window_with_a_child_item_still_inside_its_own_window_is_not_deleted(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $run = $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::factory()
            ->forFirmIntegration($connection)
            ->create(['status' => 'succeeded', 'finished_at' => now()->subDays(200)])); // past the 180d run window

        // Child item is only 10 days old — comfortably inside its OWN
        // independent 60-day window.
        $item = $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::factory()
            ->create(['firm_id' => $firm->id, 'sync_run_id' => $run->id, 'status' => 'succeeded', 'terminal_at' => now()->subDays(10)]));

        $this->sweep($firm);

        $runStillExists = $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::query()->find($run->id));
        $this->assertNotNull(
            $runStillExists,
            'The NOT EXISTS cascade-hazard guard MUST keep a sync run ineligible for deletion while it has ANY remaining child item — regardless of the PARENT\'s own age — to prevent the FK cascadeOnDelete() from destroying the still-young child.'
        );

        $itemStillExists = $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::query()->find($item->id));
        $this->assertNotNull($itemStillExists, 'The child item itself, being well within its own 60-day window, must also remain untouched.');
    }

    public function test_a_sync_run_past_its_window_whose_child_items_have_all_aged_out_is_deleted_once_the_items_sweep_first(): void
    {
        // Checkpoint 13 P3: BOTH the item sweep and the run sweep are
        // firm-data sweeps gated behind the same default-off flag — enable
        // it so this test still proves the items-before-runs ordering /
        // NOT EXISTS cascade-guard interaction it was written to prove.
        config(['integrations.retention.sweep_firm_data_enabled' => true]);

        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $run = $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::factory()
            ->forFirmIntegration($connection)
            ->create(['status' => 'succeeded', 'finished_at' => now()->subDays(200)]));

        // The child item has ALSO aged out of its own 60-day window —
        // the item sweep (which RetentionSweepJob::handle() runs BEFORE
        // the run sweep) clears it first, so the NOT EXISTS guard then
        // finds nothing blocking the parent.
        $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::factory()
            ->create(['firm_id' => $firm->id, 'sync_run_id' => $run->id, 'status' => 'succeeded', 'terminal_at' => now()->subDays(70)]));

        $this->sweep($firm);

        $runStillExists = $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::query()->find($run->id));
        $this->assertNull($runStillExists, 'Once the item sweep clears the aged-out child (same handle() invocation, items before runs), the NOT EXISTS guard no longer blocks the parent.');
    }

    public function test_a_sync_run_within_its_own_window_is_kept_regardless_of_children(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $run = $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::factory()
            ->forFirmIntegration($connection)
            ->create(['status' => 'succeeded', 'finished_at' => now()->subDays(5)]));

        $this->sweep($firm);

        $exists = $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::query()->find($run->id));
        $this->assertNotNull($exists);
    }

    // ------------------------------------------------------------
    // Resolved conflicts — 365 days from resolved_at, unresolved
    // conflicts NEVER touched
    // ------------------------------------------------------------

    public function test_a_resolved_conflict_past_365_days_is_deleted(): void
    {
        // Checkpoint 13 P3: resolved-conflicts is a firm-data sweep gated
        // behind the same default-off flag — opt in to assert deletion
        // occurs when explicitly enabled (see the sync-item variant above).
        config(['integrations.retention.sweep_firm_data_enabled' => true]);

        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $resolver = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->create(['firm_id' => $firm->id]));

        $conflict = $this->runWithFirmContext($firm, fn () => IntegrationConflict::factory()
            ->forFirmIntegration($connection)
            ->resolvedBy($resolver)
            ->create(['resolved_at' => now()->subDays(400)]));

        $this->sweep($firm);

        $exists = $this->runWithFirmContext($firm, fn () => IntegrationConflict::query()->find($conflict->id));
        $this->assertNull($exists);
    }

    public function test_a_resolved_conflict_within_365_days_is_kept(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $resolver = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->create(['firm_id' => $firm->id]));

        $conflict = $this->runWithFirmContext($firm, fn () => IntegrationConflict::factory()
            ->forFirmIntegration($connection)
            ->resolvedBy($resolver)
            ->create(['resolved_at' => now()->subDays(30)]));

        $this->sweep($firm);

        $exists = $this->runWithFirmContext($firm, fn () => IntegrationConflict::query()->find($conflict->id));
        $this->assertNotNull($exists);
    }

    public function test_an_unresolved_open_conflict_is_never_touched_no_matter_how_old_its_detected_at_is(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        $conflict = $this->runWithFirmContext($firm, fn () => IntegrationConflict::factory()
            ->forFirmIntegration($connection)
            ->create(['status' => 'detected', 'resolved_at' => null, 'detected_at' => now()->subYears(3)]));

        $this->sweep($firm);

        $exists = $this->runWithFirmContext($firm, fn () => IntegrationConflict::query()->find($conflict->id));
        $this->assertNotNull($exists, 'An unresolved conflict (status=detected, resolved_at NULL) must NEVER be deleted regardless of its own age — both the status filter and the resolved_at IS NOT NULL guard independently protect it.');
    }

    public function test_an_awaiting_review_conflict_is_never_touched(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        $conflict = $this->runWithFirmContext($firm, fn () => IntegrationConflict::factory()
            ->forFirmIntegration($connection)
            ->create(['status' => 'awaiting_review', 'resolved_at' => null, 'detected_at' => now()->subYears(3)]));

        $this->sweep($firm);

        $exists = $this->runWithFirmContext($firm, fn () => IntegrationConflict::query()->find($conflict->id));
        $this->assertNotNull($exists);
    }

    // ------------------------------------------------------------
    // OAuth states — Class A (consumed, 72h default), Class B
    // (unconsumed-expired, UNCONFIGURED -> documented no-op)
    // ------------------------------------------------------------

    public function test_a_consumed_oauth_state_past_seventy_two_hours_is_deleted(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $state = $this->runWithFirmContext($firm, fn () => IntegrationOAuthState::factory()
            ->forFirmIntegration($connection)
            ->consumed()
            ->create(['consumed_at' => now()->subHours(80)]));

        $this->sweep($firm);

        $exists = $this->runWithFirmContext($firm, fn () => IntegrationOAuthState::query()->find($state->id));
        $this->assertNull($exists);
    }

    public function test_a_consumed_oauth_state_within_seventy_two_hours_is_kept(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $state = $this->runWithFirmContext($firm, fn () => IntegrationOAuthState::factory()
            ->forFirmIntegration($connection)
            ->consumed()
            ->create(['consumed_at' => now()->subHours(10)]));

        $this->sweep($firm);

        $exists = $this->runWithFirmContext($firm, fn () => IntegrationOAuthState::query()->find($state->id));
        $this->assertNotNull($exists);
    }

    public function test_an_unconsumed_expired_oauth_state_is_never_deleted_while_unconfigured_and_logs_the_documented_line(): void
    {
        $this->assertNull(
            config('integrations.oauth_states.unconsumed_expired_retention_hours'),
            'Sanity check: this key must genuinely be unset for this test to prove anything.'
        );

        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $state = $this->runWithFirmContext($firm, fn () => IntegrationOAuthState::factory()
            ->forFirmIntegration($connection)
            ->expired()
            ->create(['expires_at' => now()->subYears(2), 'consumed_at' => null]));

        $this->sweep($firm);

        $exists = $this->runWithFirmContext($firm, fn () => IntegrationOAuthState::query()->find($state->id));
        $this->assertNotNull($exists, 'Class B (unconsumed-expired) must NEVER delete while the config key is unset — "never delete with an unsafe guessed default."');

        $logPath = storage_path('logs/integration-retention-sweep.log');
        $this->assertFileExists($logPath);
        $this->assertStringContainsString(
            'integration_retention.oauth_state_unconsumed_cleanup_not_configured',
            file_get_contents($logPath)
        );
    }

    public function test_an_unconsumed_expired_oauth_state_is_deleted_once_the_config_key_is_explicitly_set(): void
    {
        config(['integrations.oauth_states.unconsumed_expired_retention_hours' => 48]);

        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $state = $this->runWithFirmContext($firm, fn () => IntegrationOAuthState::factory()
            ->forFirmIntegration($connection)
            ->expired()
            ->create(['expires_at' => now()->subHours(60), 'consumed_at' => null]));

        $this->sweep($firm);

        $exists = $this->runWithFirmContext($firm, fn () => IntegrationOAuthState::query()->find($state->id));
        $this->assertNull($exists, 'Once explicitly configured, an unconsumed-expired row past the configured window must be deleted.');
    }

    // ------------------------------------------------------------
    // Webhook receipts — platform-level, run via the COMMAND, not the
    // per-firm job. Uses the now-fixed 7d/30d branch.
    // ------------------------------------------------------------

    public function test_a_verified_receipt_uses_the_thirty_day_window_not_the_seven_day_default(): void
    {
        DB::table('integration_webhook_receipts')->insert([
            'provider_key' => 'test',
            'routing_token_hash' => hash('sha256', 'a'.uniqid()),
            'body_hash' => hash('sha256', 'b'.uniqid()),
            'verification_outcome' => 'verified',
            'received_at' => now()->subDays(20), // > 7d, < 30d
            'acknowledgment_status' => 'acknowledged',
            'acknowledged_at' => now()->subDays(20),
            'processing_handoff_status' => 'pending',
            'retention_deadline' => now()->addDay(), // irrelevant — sweep recomputes independently
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('integrations:retention:sweep');

        $count = DB::table('integration_webhook_receipts')->where('routing_token_hash', '!=', null)->count();
        $this->assertGreaterThanOrEqual(1, $count, 'A Verified receipt at 20 days must survive — it is younger than its own 30-day window, even though it is older than the 7-day default for non-verified receipts.');
    }

    public function test_a_verified_receipt_past_thirty_days_is_deleted(): void
    {
        $hash = hash('sha256', 'v-old-'.uniqid());
        DB::table('integration_webhook_receipts')->insert([
            'provider_key' => 'test',
            'routing_token_hash' => $hash,
            'body_hash' => hash('sha256', 'body-'.uniqid()),
            'verification_outcome' => 'verified',
            'received_at' => now()->subDays(40),
            'acknowledgment_status' => 'acknowledged',
            'acknowledged_at' => now()->subDays(40),
            'processing_handoff_status' => 'pending',
            'retention_deadline' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('integrations:retention:sweep');

        $exists = DB::table('integration_webhook_receipts')->where('routing_token_hash', $hash)->exists();
        $this->assertFalse($exists);
    }

    public function test_a_malformed_receipt_uses_the_seven_day_window_not_thirty(): void
    {
        $hash = hash('sha256', 'malformed-'.uniqid());
        DB::table('integration_webhook_receipts')->insert([
            'provider_key' => 'test',
            'routing_token_hash' => $hash,
            'body_hash' => hash('sha256', 'body-'.uniqid()),
            'verification_outcome' => 'malformed',
            'failure_code' => 'malformed_payload',
            'received_at' => now()->subDays(10), // > 7d default window
            'acknowledgment_status' => 'acknowledged',
            'acknowledged_at' => now()->subDays(10),
            'processing_handoff_status' => 'pending',
            'retention_deadline' => now()->addYear(), // irrelevant — recomputed independently
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('integrations:retention:sweep');

        $exists = DB::table('integration_webhook_receipts')->where('routing_token_hash', $hash)->exists();
        $this->assertFalse($exists, 'The sweep must independently recompute from verification_outcome/received_at — NEVER trust the (deliberately wrong, far-future) stored retention_deadline column alone.');
    }

    // ------------------------------------------------------------
    // Checkpoint 9 additions — integration_usage_records: NOT swept
    // today (no sweepUsageRecords() method exists per the frozen
    // design's "no default retention -> fail-safe no-op" ruling), and
    // RetentionGovernanceRegistryService correctly reports
    // NOT_CONFIGURED_FAIL_SAFE for the usage_records category while the
    // config key is unset.
    // ------------------------------------------------------------

    public function test_retention_sweep_job_has_no_sweepusagerecords_method(): void
    {
        $reflection = new \ReflectionClass(RetentionSweepJob::class);

        $this->assertFalse(
            $reflection->hasMethod('sweepUsageRecords'),
            'RetentionSweepJob must NOT have a sweepUsageRecords() method at Checkpoint 9 — the frozen design ships '.
            'integration_usage_records.retention_deadline with NO default and explicitly defers building a sweep '.
            'method until a future checkpoint. If this assertion fails, a sweepUsageRecords() method was added — '.
            'that is a scope deviation from the frozen design, not something to silently accommodate.'
        );
    }

    public function test_a_very_old_usage_record_with_a_past_retention_deadline_is_never_swept_today(): void
    {
        config(['integrations.usage_records.retention_days' => 1]);

        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $record = $this->runWithFirmContext($firm, fn () => IntegrationUsageRecord::factory()
            ->forFirmIntegration($connection)
            ->create(['occurred_at' => now()->subYears(5), 'retention_deadline' => now()->subYears(4)]));

        $this->sweep($firm);

        $exists = $this->runWithFirmContext($firm, fn () => IntegrationUsageRecord::query()->find($record->id));
        $this->assertNotNull($exists, 'No live sweep method exists for integration_usage_records yet — a record must survive RetentionSweepJob::handle() regardless of how far past its own retention_deadline it is.');
    }

    public function test_retention_governance_registry_reports_not_configured_fail_safe_for_usage_records_while_the_env_var_is_unset(): void
    {
        $this->assertNull(
            config('integrations.usage_records.retention_days'),
            'Sanity check: this key must genuinely be unset for this test to prove anything.'
        );

        $registry = new RetentionGovernanceRegistryService();

        $this->assertSame(
            RetentionGovernanceRegistryService::STATUS_NOT_CONFIGURED_FAIL_SAFE,
            $registry->statusFor('usage_records')
        );

        $category = $registry->categoryFor('usage_records');
        $this->assertNotNull($category);
        $this->assertContains('integration_usage_records', $category['tables']);
        $this->assertSame('integrations.usage_records.retention_days', $category['config_key']);
        $this->assertNull($category['current_default'], 'current_default must reflect the live, currently-unset config value, not a hardcoded copy.');
    }

    public function test_retention_governance_registry_current_default_reflects_the_live_config_value_once_set(): void
    {
        config(['integrations.usage_records.retention_days' => 60]);

        $registry = new RetentionGovernanceRegistryService();
        $category = $registry->categoryFor('usage_records');

        $this->assertSame(60, $category['current_default'], 'current_default must be resolved LIVE via config() at call time, never a hardcoded second copy of the number.');
    }

    // ------------------------------------------------------------
    // Webhook events — 400d redact / 2555d delete, two-stage
    // ------------------------------------------------------------

    public function test_a_processed_event_past_its_redact_deadline_has_its_content_redacted_but_the_row_survives(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        $event = $this->runWithFirmContext($firm, fn () => IntegrationInboundWebhookEvent::factory()
            ->forFirmIntegration($connection)
            ->processed()
            ->create([
                'payload_reference_json' => ['resource_type' => 'contact', 'resource_id' => '1'],
                'payload_hash' => hash('sha256', 'x'),
                'retention_deadline' => now()->subDay(),
                'received_at' => now()->subDays(450),
            ]));

        $this->sweep($firm);

        $fresh = $this->runWithFirmContext($firm, fn () => IntegrationInboundWebhookEvent::query()->find($event->id));
        $this->assertNotNull($fresh, 'A row only past its 400d REDACT deadline (not the 2555d delete horizon) must survive.');
        $this->assertSame([], $fresh->payload_reference_json);
        $this->assertNull($fresh->payload_hash);
        $this->assertNull($fresh->receipt_body_hash);
        $this->assertNull($fresh->failure_detail);
    }

    public function test_a_processed_event_past_the_delete_horizon_is_hard_deleted(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        $event = $this->runWithFirmContext($firm, fn () => IntegrationInboundWebhookEvent::factory()
            ->forFirmIntegration($connection)
            ->processed()
            ->create([
                'retention_deadline' => now()->subYears(6),
                'received_at' => now()->subDays(2600),
            ]));

        $this->sweep($firm);

        $exists = $this->runWithFirmContext($firm, fn () => IntegrationInboundWebhookEvent::query()->find($event->id));
        $this->assertNull($exists);
    }

    public function test_a_non_terminal_event_past_its_retention_deadline_is_never_redacted_or_deleted(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        $event = $this->runWithFirmContext($firm, fn () => IntegrationInboundWebhookEvent::factory()
            ->forFirmIntegration($connection)
            ->create([
                'status' => 'verified', // non-terminal
                'terminal_at' => null,
                'payload_reference_json' => ['resource_type' => 'contact'],
                'payload_hash' => hash('sha256', 'y'),
                'retention_deadline' => now()->subYear(),
                'received_at' => now()->subDays(2600),
            ]));

        $this->sweep($firm);

        $fresh = $this->runWithFirmContext($firm, fn () => IntegrationInboundWebhookEvent::query()->find($event->id));
        $this->assertNotNull($fresh, 'A stuck NON-terminal row must never be deleted, no matter how far past its retention_deadline/received_at.');
        $this->assertNotSame([], $fresh->payload_reference_json, 'A stuck non-terminal row must never be redacted either — only a genuinely terminal row is eligible.');
    }

    public function test_a_non_terminal_stuck_event_is_counted_in_the_audit_log(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        $this->runWithFirmContext($firm, fn () => IntegrationInboundWebhookEvent::factory()
            ->forFirmIntegration($connection)
            ->create([
                'status' => 'verified',
                'terminal_at' => null,
                'retention_deadline' => now()->subYear(),
                'received_at' => now()->subDays(2600),
            ]));

        $this->sweep($firm);

        $logPath = storage_path('logs/integration-retention-sweep.log');
        $this->assertStringContainsString('integration_retention.stuck_terminal_deadline_row', file_get_contents($logPath));
    }

    // ------------------------------------------------------------
    // Batch/resumability — interrupt mid-run, confirm idempotent resume
    // ------------------------------------------------------------

    public function test_more_eligible_rows_than_one_batch_are_all_swept_across_multiple_batch_iterations(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        $ids = [];
        for ($i = 0; $i < 7; $i++) {
            $ids[] = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()
                ->forFirmIntegration($connection)
                ->completed()
                ->create(['completed_at' => now()->subDays(40)]))->id;
        }

        $this->sweep($firm, batchSize: 2); // forces 4 batch iterations (2+2+2+1)

        $remaining = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::query()->whereIn('id', $ids)->count());
        $this->assertSame(0, $remaining, 'All 7 eligible rows must be swept across multiple 2-row batches, not just the first batch.');
    }

    public function test_a_run_interrupted_by_a_low_max_batches_ceiling_resumes_and_eventually_clears_everything_on_a_later_invocation(): void
    {
        config(['integrations.retention.platform_max_batches_per_run' => 1]);

        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()
                ->forFirmIntegration($connection)
                ->completed()
                ->create(['completed_at' => now()->subDays(40)]))->id;
        }

        // "Interrupted" run 1: max_batches_per_run=1 with batchSize=2
        // sweeps only the first 2 of 5 eligible rows for THIS table,
        // simulating a run cut short.
        $this->sweep($firm, batchSize: 2);

        $remainingAfterFirstRun = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::query()->whereIn('id', $ids)->count());
        $this->assertSame(3, $remainingAfterFirstRun, 'Exactly 2 of 5 must have been swept in the artificially-truncated first run.');

        // Resume: run it again. Idempotent — no error, no double-delete
        // artifact, the previously-untouched rows are simply picked up
        // fresh on this invocation.
        $this->sweep($firm, batchSize: 2);
        $this->sweep($firm, batchSize: 2);
        $this->sweep($firm, batchSize: 2);

        $remainingAfterResume = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::query()->whereIn('id', $ids)->count());
        $this->assertSame(0, $remainingAfterResume, 'Repeated invocations must be safely idempotent and eventually clear every eligible row.');
    }

    public function test_a_batch_sweep_never_touches_an_ineligible_row_interleaved_among_eligible_ones(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        $eligible = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->completed()->create(['completed_at' => now()->subDays(40)]));
        $ineligible = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()->forFirmIntegration($connection)->completed()->create(['completed_at' => now()->subDays(2)]));

        $this->sweep($firm, batchSize: 1);

        $this->assertNull($this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::query()->find($eligible->id)));
        $this->assertNotNull($this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::query()->find($ineligible->id)));
    }

    // ------------------------------------------------------------
    // Sanitized audit counts only — never row-level content
    // ------------------------------------------------------------

    public function test_the_audit_logger_rejects_an_unknown_event_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new RetentionSweepAuditLogger())->record('some.unknown.event_name');
    }

    public function test_the_persisted_log_output_contains_only_the_four_allowed_keys_never_row_ids_or_error_text(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()
            ->forFirmIntegration($connection)
            ->completed()
            ->create(['completed_at' => now()->subDays(40), 'last_error' => 'super-secret-raw-provider-detail-must-never-leak']));

        $this->sweep($firm);

        $logPath = storage_path('logs/integration-retention-sweep.log');
        $this->assertFileExists($logPath);
        $contents = file_get_contents($logPath);

        $this->assertStringNotContainsString('super-secret-raw-provider-detail-must-never-leak', $contents);

        foreach (explode("\n", trim($contents)) as $line) {
            if ($line === '') {
                continue;
            }

            // Each log line is "event_name {json-context}" (Laravel's
            // single-line formatter) — extract and validate the JSON
            // context object's key set.
            $jsonStart = strpos($line, '{');
            if ($jsonStart === false) {
                continue;
            }

            $decoded = json_decode(substr($line, $jsonStart), true);
            if (! is_array($decoded)) {
                continue;
            }

            $allowedKeys = ['table', 'firm_id', 'count', 'dry_run'];
            foreach (array_keys($decoded) as $key) {
                $this->assertContains($key, $allowedKeys, "Log context key '{$key}' is not in the allowed {table, firm_id, count, dry_run} shape.");
            }
        }
    }

    public function test_a_dry_run_never_actually_deletes_anything(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $event = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()
            ->forFirmIntegration($connection)
            ->completed()
            ->create(['completed_at' => now()->subDays(40)]));

        $job = new RetentionSweepJob($firm->id, dryRun: true, batchSize: 500);
        $job->handle(new RetentionSweepAuditLogger());

        $exists = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::query()->find($event->id));
        $this->assertNotNull($exists, 'A dry run must never actually delete an eligible row.');
    }
}
