<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationConflict;
use App\Integrations\Models\IntegrationOutboxEvent;
use App\Integrations\Models\IntegrationSyncItem;
use App\Integrations\Models\IntegrationSyncRun;
use App\Integrations\Services\RetentionSweepAuditLogger;
use App\Jobs\RetentionSweepJob;
use App\Models\Firm;
use App\Models\FirmUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RetentionSweepLegalHoldGuardTest — Checkpoint 13 P3 (finding #5,
 * DISABLE_BY_DEFAULT — agent-13h-testing-release-review.md §3/§4 item 2;
 * frozen-test-closure-plan.md §4). Proves the new
 * `integrations.retention.sweep_firm_data_enabled` kill-switch:
 *
 *  - When the flag is at its DEFAULT (false), the three FIRM-DATA,
 *    client/matter-adjacent sweeps (sweepSyncItems, sweepSyncRuns,
 *    sweepResolvedConflicts) are genuinely skipped — the rows survive,
 *    and a clear, greppable skip line is logged (never a silent no-op).
 *  - When the flag is EXPLICITLY enabled, all three genuinely run and
 *    delete their eligible rows.
 *  - The guard is correctly SCOPED: the platform-owned webhook-receipts /
 *    outbox / OAuth-state sweeps are NOT gated by this flag and continue
 *    to run even while firm-data sweeps are disabled.
 *
 * The skip line is emitted via the default `Log` facade
 * (RetentionSweepJob::firmDataSweepDisabled()), so this test routes the
 * default channel to an isolated temp file for a deterministic,
 * non-shared assertion — the retention-sweep AUDIT logger uses its own
 * separate Log::build() channel and is unaffected.
 */
final class RetentionSweepLegalHoldGuardTest extends TestCase
{
    use RefreshDatabase;

    private ?string $captureLogPath = null;

    protected function tearDown(): void
    {
        if ($this->captureLogPath !== null && file_exists($this->captureLogPath)) {
            @unlink($this->captureLogPath);
        }

        parent::tearDown();
    }

    /**
     * Routes the default Log channel (which firmDataSweepDisabled() uses)
     * to a fresh, uniquely-named temp file this test wholly owns, so its
     * skip-line assertions never race a line another test wrote to the
     * shared default log. A brand-new channel NAME forces the LogManager
     * to resolve it fresh rather than reuse an already-cached default.
     */
    private function captureDefaultLog(): string
    {
        $this->captureLogPath = storage_path('logs/ckpt13-legal-hold-guard-'.uniqid().'.log');

        config([
            'logging.default' => 'ckpt13_legal_hold_capture',
            'logging.channels.ckpt13_legal_hold_capture' => [
                'driver' => 'single',
                'path' => $this->captureLogPath,
                'level' => 'debug',
            ],
        ]);

        return $this->captureLogPath;
    }

    private function connection(Firm $firm): FirmIntegration
    {
        return $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
    }

    private function sweep(Firm $firm): void
    {
        $job = new RetentionSweepJob($firm->id, false, 500);
        $job->handle(new RetentionSweepAuditLogger());
    }

    private function agedSyncItem(Firm $firm, FirmIntegration $connection): IntegrationSyncItem
    {
        $run = $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration($connection)->create(['status' => 'succeeded']));

        return $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::factory()
            ->create(['firm_id' => $firm->id, 'sync_run_id' => $run->id, 'status' => 'succeeded', 'terminal_at' => now()->subDays(90)]));
    }

    private function agedSyncRun(Firm $firm, FirmIntegration $connection): IntegrationSyncRun
    {
        // No child items — so the NOT EXISTS cascade guard would allow
        // deletion once the sweep is permitted to run at all.
        return $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::factory()
            ->forFirmIntegration($connection)
            ->create(['status' => 'succeeded', 'finished_at' => now()->subDays(300)]));
    }

    private function agedResolvedConflict(Firm $firm, FirmIntegration $connection): IntegrationConflict
    {
        $resolver = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->create(['firm_id' => $firm->id]));

        return $this->runWithFirmContext($firm, fn () => IntegrationConflict::factory()
            ->forFirmIntegration($connection)
            ->resolvedBy($resolver)
            ->create(['resolved_at' => now()->subDays(500)]));
    }

    // ------------------------------------------------------------
    // Sanity: the flag genuinely defaults OFF (so every "skipped by
    // default" assertion below is proving something real).
    // ------------------------------------------------------------

    public function test_the_firm_data_sweep_flag_defaults_to_false(): void
    {
        $this->assertFalse(
            (bool) config('integrations.retention.sweep_firm_data_enabled'),
            'integrations.retention.sweep_firm_data_enabled must default to false — the entire kill-switch depends on it.'
        );
    }

    // ------------------------------------------------------------
    // Default OFF — every firm-data sweep is skipped, rows survive, and
    // a greppable skip reason is logged for each.
    // ------------------------------------------------------------

    public function test_sync_items_sweep_is_skipped_and_logs_the_skip_reason_while_the_flag_is_off(): void
    {
        $logPath = $this->captureDefaultLog();

        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $item = $this->agedSyncItem($firm, $connection);

        $this->sweep($firm);

        $survivor = $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::query()->find($item->id));
        $this->assertNotNull($survivor, 'A 90-day-old terminal sync item must SURVIVE while the firm-data sweep flag is off (legal-hold protection).');

        $this->assertFileExists($logPath);
        $contents = file_get_contents($logPath);
        $this->assertStringContainsString('integration_retention.firm_data_sweep_skipped_disabled', $contents);
        $this->assertStringContainsString('integration_sync_items', $contents);
    }

    public function test_sync_runs_sweep_is_skipped_and_logs_the_skip_reason_while_the_flag_is_off(): void
    {
        $logPath = $this->captureDefaultLog();

        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $run = $this->agedSyncRun($firm, $connection);

        $this->sweep($firm);

        $survivor = $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::query()->find($run->id));
        $this->assertNotNull($survivor, 'A 300-day-old childless terminal sync run must SURVIVE while the firm-data sweep flag is off.');

        $contents = file_get_contents($logPath);
        $this->assertStringContainsString('integration_retention.firm_data_sweep_skipped_disabled', $contents);
        $this->assertStringContainsString('integration_sync_runs', $contents);
    }

    public function test_resolved_conflicts_sweep_is_skipped_and_logs_the_skip_reason_while_the_flag_is_off(): void
    {
        $logPath = $this->captureDefaultLog();

        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $conflict = $this->agedResolvedConflict($firm, $connection);

        $this->sweep($firm);

        $survivor = $this->runWithFirmContext($firm, fn () => IntegrationConflict::query()->find($conflict->id));
        $this->assertNotNull($survivor, 'A 500-day-old resolved conflict must SURVIVE while the firm-data sweep flag is off.');

        $contents = file_get_contents($logPath);
        $this->assertStringContainsString('integration_retention.firm_data_sweep_skipped_disabled', $contents);
        $this->assertStringContainsString('integration_conflicts', $contents);
    }

    // ------------------------------------------------------------
    // Explicitly ENABLED — all three firm-data sweeps genuinely run.
    // ------------------------------------------------------------

    public function test_all_three_firm_data_sweeps_delete_their_eligible_rows_once_the_flag_is_explicitly_enabled(): void
    {
        config(['integrations.retention.sweep_firm_data_enabled' => true]);

        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $item = $this->agedSyncItem($firm, $connection);
        $conflict = $this->agedResolvedConflict($firm, $connection);
        // A separate connection for the childless run so the item's parent
        // run (which now has a child) does not interfere with this proof.
        $runConnection = $this->connection($firm);
        $run = $this->agedSyncRun($firm, $runConnection);

        $this->sweep($firm);

        $this->assertNull(
            $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::query()->find($item->id)),
            'With the flag enabled, an aged-out terminal sync item must be deleted.'
        );
        $this->assertNull(
            $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::query()->find($run->id)),
            'With the flag enabled, an aged-out childless terminal sync run must be deleted.'
        );
        $this->assertNull(
            $this->runWithFirmContext($firm, fn () => IntegrationConflict::query()->find($conflict->id)),
            'With the flag enabled, an aged-out resolved conflict must be deleted.'
        );
    }

    // ------------------------------------------------------------
    // Scope proof — the platform-owned outbox sweep is NOT gated by the
    // firm-data flag, so it still runs even while firm-data sweeps are
    // disabled (per the design: only sync items/runs/resolved conflicts
    // are gated).
    // ------------------------------------------------------------

    public function test_the_outbox_sweep_still_runs_while_the_firm_data_flag_is_off(): void
    {
        // Flag left at its default (off).
        $this->assertFalse((bool) config('integrations.retention.sweep_firm_data_enabled'));

        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        $agedCompletedEvent = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()
            ->forFirmIntegration($connection)
            ->completed()
            ->create(['completed_at' => now()->subDays(60)]));

        $this->sweep($firm);

        $this->assertNull(
            $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::query()->find($agedCompletedEvent->id)),
            'The outbox sweep is NOT gated by the firm-data flag — an aged-out completed outbox event must still be deleted even while firm-data sweeps are disabled.'
        );
    }
}
