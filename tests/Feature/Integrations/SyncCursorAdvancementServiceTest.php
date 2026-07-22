<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Enums\SyncItemStatus;
use App\Integrations\Enums\SyncRunStatus;
use App\Integrations\Exceptions\CursorVersionConflictException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationSyncCursor;
use App\Integrations\Models\IntegrationSyncItem;
use App\Integrations\Models\IntegrationSyncRun;
use App\Integrations\Services\SyncCursorService;
use App\Integrations\Services\SyncItemService;
use App\Integrations\Services\SyncRunService;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SyncCursorAdvancementServiceTest — Checkpoint 6
 * (agent-6e-sync-run-item-cursor-semantics.md §3-§4;
 * agent-6h-test-plan-and-review.md §6 item 12). The single most
 * important invariant in the Checkpoint 6 design: cursor_value may
 * change ONLY inside the same transaction that commits the batch's
 * terminal item-status writes, and a cursor_version mismatch rolls back
 * the ENTIRE batch transaction, item writes included — never a silent
 * serialize-and-retry.
 *
 * Note on scope: no worker/batch-loop dispatcher exists at Checkpoint 6
 * (frozen-design-post-review.md §14) — SyncCursorService itself never
 * calls SyncRunService::transitionStatus() on a CAS conflict (there is
 * no orchestration code to do so yet). The tests below prove (a) the
 * cursor primitive's own CAS/rollback behavior directly, and (b)
 * demonstrate the primitives COMPOSE correctly into the frozen
 * "run terminates Failed with cursor_version_conflict" behavior, in the
 * same shape a future worker would call them — not that SyncCursorService
 * performs this orchestration itself.
 */
class SyncCursorAdvancementServiceTest extends TestCase
{
    use RefreshDatabase;

    private SyncCursorService $cursorService;

    private SyncItemService $itemService;

    private SyncRunService $runService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cursorService = new SyncCursorService();
        $this->itemService = new SyncItemService();
        $this->runService = new SyncRunService();
    }

    // ------------------------------------------------------------
    // Cursor advances together with item writes on a fully-safe batch
    // ------------------------------------------------------------

    public function test_cursor_advances_in_the_same_transaction_as_a_fully_succeeded_batch(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $run = $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration($connection)->running()->create());
        $cursor = $this->runWithFirmContext($firm, fn () => IntegrationSyncCursor::factory()->forFirmIntegration($connection)->create());

        $this->runWithFirmContext($firm, function () use ($firm, $run, $cursor) {
            DB::transaction(function () use ($firm, $run, $cursor) {
                $this->itemService->recordAttempt($firm->id, $run->id, 'contact', 'App\\Models\\Contact', 1, (string) Str::uuid(), SyncItemStatus::Succeeded);
                $this->itemService->recordAttempt($firm->id, $run->id, 'contact', 'App\\Models\\Contact', 2, (string) Str::uuid(), SyncItemStatus::Succeeded);

                $this->cursorService->advance($cursor->id, $cursor->cursor_version, 'cursor-token-1');
            });
        });

        $fresh = $this->runWithFirmContext($firm, fn () => IntegrationSyncCursor::query()->findOrFail($cursor->id));
        $this->assertSame('cursor-token-1', $fresh->cursor_value);
        $this->assertSame(1, $fresh->cursor_version);

        $itemCount = $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::query()->where('sync_run_id', $run->id)->count());
        $this->assertSame(2, $itemCount);
    }

    // ------------------------------------------------------------
    // Cursor-does-not-advance-on-failure proof
    // ------------------------------------------------------------

    public function test_cursor_does_not_advance_when_the_batch_leaves_a_pending_item(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $run = $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration($connection)->running()->create());
        $cursor = $this->runWithFirmContext($firm, fn () => IntegrationSyncCursor::factory()->forFirmIntegration($connection)->create());

        $beforeValue = $cursor->cursor_value;
        $beforeVersion = $cursor->cursor_version;

        // The batch loop's own logic (agent-6e §3): write item terminal
        // states for this attempt, but because one item is left Pending
        // (still outstanding required work), the batch never calls
        // advance() at all — this is the correct behavior, not a bug to
        // work around.
        $this->runWithFirmContext($firm, function () use ($firm, $run) {
            DB::transaction(function () use ($firm, $run) {
                $this->itemService->recordAttempt($firm->id, $run->id, 'contact', 'App\\Models\\Contact', 1, (string) Str::uuid(), SyncItemStatus::Succeeded);
                $pendingItem = $this->itemService->recordAttempt($firm->id, $run->id, 'contact', null, null, (string) Str::uuid(), SyncItemStatus::Pending);

                $this->assertTrue($pendingItem->blocksCursorAdvancement());
                // Deliberately do NOT call $this->cursorService->advance()
                // here — the batch is not cursor-safe.
            });
        });

        $fresh = $this->runWithFirmContext($firm, fn () => IntegrationSyncCursor::query()->findOrFail($cursor->id));
        $this->assertSame($beforeValue, $fresh->cursor_value);
        $this->assertSame($beforeVersion, $fresh->cursor_version, 'The cursor must be completely untouched when the batch is not cursor-safe.');

        // The item writes themselves DID commit — cursor safety and item
        // durability are independent concerns.
        $itemCount = $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::query()->where('sync_run_id', $run->id)->count());
        $this->assertSame(2, $itemCount);
    }

    public function test_cursor_does_not_advance_when_the_batch_leaves_a_retrying_item(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $run = $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration($connection)->running()->create());
        $cursor = $this->runWithFirmContext($firm, fn () => IntegrationSyncCursor::factory()->forFirmIntegration($connection)->withCursorValue('prior-value')->create());

        $this->runWithFirmContext($firm, function () use ($run) {
            // Test-fixture fix (checkpoint-06 verification pass):
            // IntegrationSyncItemFactory::failedRetryable()'s own default
            // next_attempt_at is now()->addMinutes(5) (a deliberately
            // future, "backed off, not yet due" default for other
            // callers). SyncItemService::claimForRetry()'s guard is
            // `WHERE status = 'failed_retryable' AND next_attempt_at <=
            // now()`, so claiming this fixture unmodified always returns
            // null — the assertNotNull() below would fail before ever
            // reaching the blocksCursorAdvancement() assertion this test
            // exists to prove. Overriding next_attempt_at to the past
            // here (rather than changing the shared factory default)
            // makes the item due for retry now, matching what this test
            // actually claims to exercise.
            $failed = IntegrationSyncItem::factory()->forSyncRun($run)->failedRetryable()->create(['next_attempt_at' => now()->subMinute()]);
            $retrying = $this->itemService->claimForRetry($failed->id);
            $this->assertNotNull($retrying);
            $this->assertTrue($retrying->blocksCursorAdvancement());
        });

        $fresh = $this->runWithFirmContext($firm, fn () => IntegrationSyncCursor::query()->findOrFail($cursor->id));
        $this->assertSame('prior-value', $fresh->cursor_value, 'A Retrying item must also block advancement — the cursor is untouched.');
        $this->assertSame(0, $fresh->cursor_version);
    }

    // ------------------------------------------------------------
    // cursor_version CAS conflict — reject the WHOLE batch transaction
    // ------------------------------------------------------------

    public function test_advance_throws_on_a_stale_cursor_version(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $cursor = $this->runWithFirmContext($firm, fn () => IntegrationSyncCursor::factory()->forFirmIntegration($connection)->create());

        $this->expectException(CursorVersionConflictException::class);

        $this->runWithFirmContext($firm, function () use ($cursor) {
            $this->cursorService->advance($cursor->id, $cursor->cursor_version + 5, 'irrelevant');
        });
    }

    public function test_a_cursor_version_conflict_rolls_back_the_entire_batch_transaction_including_item_writes(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $run = $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::factory()->forFirmIntegration($connection)->running()->create());
        $cursor = $this->runWithFirmContext($firm, fn () => IntegrationSyncCursor::factory()->forFirmIntegration($connection)->create());

        $staleExpectedVersion = $cursor->cursor_version; // 0

        // An out-of-band actor (a SuperAdmin reset, a disconnect flow,
        // etc.) advances the cursor BEFORE this batch's own transaction
        // begins its cursor write — its own transaction has already
        // committed by this point.
        $this->runWithFirmContext($firm, function () use ($cursor) {
            $this->cursorService->advance($cursor->id, $cursor->cursor_version, 'out-of-band-value');
        });

        $externalIdOfDoomedItem = (string) Str::uuid();

        $caught = null;

        try {
            $this->runWithFirmContext($firm, function () use ($firm, $run, $cursor, $staleExpectedVersion, $externalIdOfDoomedItem) {
                DB::transaction(function () use ($firm, $run, $cursor, $staleExpectedVersion, $externalIdOfDoomedItem) {
                    // Item-status writes that WOULD have committed had the
                    // cursor CAS succeeded.
                    $this->itemService->recordAttempt($firm->id, $run->id, 'contact', 'App\\Models\\Contact', 999, $externalIdOfDoomedItem, SyncItemStatus::Succeeded);

                    // Reads the STALE version (0) — the out-of-band actor
                    // already moved it to 1 — so this must throw and
                    // propagate, rolling back the whole transaction.
                    $this->cursorService->advance($cursor->id, $staleExpectedVersion, 'batch-value-that-must-never-land');
                });
            });
        } catch (CursorVersionConflictException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(CursorVersionConflictException::class, $caught);

        // The item write from INSIDE the doomed transaction must not
        // exist — the whole batch rolled back, not just the cursor half.
        $itemExists = $this->runWithFirmContext(
            $firm,
            fn () => IntegrationSyncItem::query()->where('sync_run_id', $run->id)->where('external_id', $externalIdOfDoomedItem)->exists(),
        );
        $this->assertFalse($itemExists, 'Item-status writes made inside the doomed batch transaction must be rolled back along with the cursor write.');

        // The cursor must be exactly as the out-of-band actor left it —
        // never further mutated by the failed advance attempt (0 rows
        // affected means nothing was written).
        $fresh = $this->runWithFirmContext($firm, fn () => IntegrationSyncCursor::query()->findOrFail($cursor->id));
        $this->assertSame('out-of-band-value', $fresh->cursor_value);
        $this->assertSame(1, $fresh->cursor_version);

        // Composition proof: this is exactly the caller pattern a future
        // worker uses — catch the exception and terminate the run
        // Failed with error_summary = 'cursor_version_conflict', per
        // frozen-design-post-review.md §8 / the exception's own docblock.
        $terminatedRun = $this->runWithFirmContext(
            $firm,
            fn () => $this->runService->transitionStatus($run, SyncRunStatus::Failed, 'cursor_version_conflict'),
        );

        $this->assertSame(SyncRunStatus::Failed, $terminatedRun->status);
        $this->assertSame('cursor_version_conflict', $terminatedRun->error_summary);
        $this->assertNotNull($terminatedRun->finished_at);
    }

    public function test_invalidate_also_uses_cas_and_throws_on_stale_version(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();
        $cursor = $this->runWithFirmContext($firm, fn () => IntegrationSyncCursor::factory()->forFirmIntegration($connection)->withCursorValue('some-value')->create());

        $this->expectException(CursorVersionConflictException::class);

        $this->runWithFirmContext($firm, function () use ($cursor) {
            $this->cursorService->invalidate($cursor->id, $cursor->cursor_version + 1);
        });
    }
}
