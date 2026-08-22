<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Enums\OutboxEventStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationOutboxEvent;
use App\Integrations\Services\IntegrationOutboxEventService;
use App\Integrations\Services\IntegrationRequeueAuditLogger;
use App\Models\Firm;
use App\Services\TimelineEventRecorder;
use App\Services\WebhookRetryPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * IntegrationOutboxEventClaimServiceTest — Checkpoint 6
 * (reviews/checkpoint-06/frozen-design-post-review.md §7;
 * agent-6h-test-plan-and-review.md §6 items 8-10). The core two-worker-
 * cannot-double-claim proof, the stale-worker-cannot-complete-
 * reclaimed-row proof, the stale-lock recovery boundary, and the exact
 * atomic-SQL-shape assertion for IntegrationOutboxEventService::claim().
 */
class IntegrationOutboxEventClaimServiceTest extends TestCase
{
    use RefreshDatabase;

    private IntegrationOutboxEventService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new IntegrationOutboxEventService(new WebhookRetryPolicyService, new TimelineEventRecorder, new IntegrationRequeueAuditLogger);
    }

    // ------------------------------------------------------------
    // Two-worker-cannot-double-claim proof
    // ------------------------------------------------------------

    public function test_a_pending_row_can_be_claimed_exactly_once_and_a_second_immediate_claim_returns_nothing(): void
    {
        $firm = Firm::factory()->create();
        $event = IntegrationOutboxEvent::factory()
            ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
            ->create();

        $firstClaim = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10));

        $this->assertCount(1, $firstClaim);
        $claimed = $firstClaim->first();
        $this->assertSame($event->id, $claimed->id);
        $this->assertSame(OutboxEventStatus::Processing, $claimed->status);
        $this->assertNotNull($claimed->lock_token);
        $this->assertSame(1, $claimed->attempts);

        $secondClaim = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10));

        $this->assertCount(0, $secondClaim, 'A second immediate claim against the same pool must return zero rows.');

        $freshLockToken = $this->runWithFirmContext(
            $firm,
            fn () => DB::table('integration_outbox_events')->where('id', $event->id)->value('lock_token'),
        );

        $this->assertSame(
            $claimed->lock_token,
            $freshLockToken,
            'The row\'s lock_token must remain the FIRST claim\'s token, never a second, discarded token.'
        );
    }

    public function test_claiming_multiple_pending_rows_respects_the_limit_and_ordering(): void
    {
        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        $older = IntegrationOutboxEvent::factory()->forFirmIntegration($connection)
            ->create(['next_attempt_at' => now()->subMinutes(5)]);
        $newer = IntegrationOutboxEvent::factory()->forFirmIntegration($connection)
            ->create(['next_attempt_at' => now()->subMinute()]);
        IntegrationOutboxEvent::factory()->forFirmIntegration($connection)
            ->create(['next_attempt_at' => now()->addMinutes(5)]); // not yet eligible

        $claimed = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 1));

        $this->assertCount(1, $claimed);
        $this->assertSame($older->id, $claimed->first()->id, 'The earliest-eligible next_attempt_at must be claimed first.');

        $claimedSecond = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10));
        $this->assertCount(1, $claimedSecond, 'Only the newer eligible row remains; the future-dated row must not be claimed.');
        $this->assertSame($newer->id, $claimedSecond->first()->id);
    }

    public function test_claim_never_crosses_firm_boundaries(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        IntegrationOutboxEvent::factory()->forFirmIntegration(FirmIntegration::factory()->forFirm($firmB)->create())->create();

        $claimed = $this->runWithFirmContext($firmA, fn () => $this->service->claim($firmA->id, 10));

        $this->assertCount(0, $claimed);
    }

    // ------------------------------------------------------------
    // Stale-worker-cannot-complete-reclaimed-row proof
    // ------------------------------------------------------------

    public function test_stale_worker_cannot_complete_a_row_that_has_been_reclaimed_by_another_worker(): void
    {
        $firm = Firm::factory()->create();
        $event = IntegrationOutboxEvent::factory()
            ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
            ->create();

        // Worker A claims the row (token A).
        $claimA = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10))->first();
        $tokenA = $claimA->lock_token;
        $this->assertSame(1, $claimA->attempts);

        // Force the lock stale (past the 15-minute bound).
        $this->runWithFirmContext($firm, function () use ($event) {
            DB::table('integration_outbox_events')->where('id', $event->id)->update([
                'locked_at' => now()->subMinutes(20),
            ]);
        });

        // Worker B reclaims the now-stale row (token B).
        $claimB = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10))->first();
        $tokenB = $claimB->lock_token;
        $this->assertNotSame($tokenA, $tokenB);
        $this->assertSame(2, $claimB->attempts, 'Reclaiming increments attempts a second time.');

        $processingRow = $this->runWithFirmContext($firm, fn () => DB::table('integration_outbox_events')->where('id', $event->id)->first());
        $this->assertSame('processing', $processingRow->status);
        $this->assertSame($tokenB, $processingRow->lock_token);

        // Worker A (stale token) attempts completion — must be rejected,
        // and the row must remain processing under token B.
        $staleCompletion = $this->runWithFirmContext($firm, fn () => $this->service->complete($event->id, $tokenA));
        $this->assertNull($staleCompletion, 'A stale token must not be able to complete a row now owned by another worker.');

        $stillOwnedByB = $this->runWithFirmContext($firm, fn () => DB::table('integration_outbox_events')->where('id', $event->id)->first());
        $this->assertSame('processing', $stillOwnedByB->status);
        $this->assertSame($tokenB, $stillOwnedByB->lock_token, 'Worker B\'s active claim must be unaffected by worker A\'s stale completion attempt.');

        // Worker B (correct token) completes successfully.
        $successfulCompletion = $this->runWithFirmContext($firm, fn () => $this->service->complete($event->id, $tokenB));
        $this->assertNotNull($successfulCompletion);
        $this->assertSame(OutboxEventStatus::Completed, $successfulCompletion->status);
        $this->assertNotNull($successfulCompletion->completed_at);

        // Worker A attempts completion again — now rejected because
        // status <> 'processing' (terminal), proving the status clause
        // is independently load-bearing from the token clause.
        $secondStaleAttempt = $this->runWithFirmContext($firm, fn () => $this->service->complete($event->id, $tokenA));
        $this->assertNull($secondStaleAttempt);

        $finalRow = $this->runWithFirmContext($firm, fn () => DB::table('integration_outbox_events')->where('id', $event->id)->first());
        $this->assertSame('completed', $finalRow->status);
    }

    // ------------------------------------------------------------
    // Stale-lock recovery boundary
    // ------------------------------------------------------------

    public function test_a_lock_at_exactly_14_minutes_59_seconds_is_not_reclaimed(): void
    {
        $firm = Firm::factory()->create();
        $event = IntegrationOutboxEvent::factory()
            ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
            ->create();

        $originalToken = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10))->first()->lock_token;

        // Checkpoint 13 (frozen-test-closure-plan.md §4): anchor the
        // fixture's locked_at to PostgreSQL's OWN statement_timestamp()
        // rather than PHP's now(). claim()'s stale-lock predicate compares
        // locked_at against `statement_timestamp() - interval '15 minutes'`
        // — a Postgres-side clock. Deriving the fixture's locked_at from
        // PHP's now() instead introduces cross-process (PHP vs Postgres)
        // clock drift that, right at the 14:59 boundary, could flip this
        // strict-inequality proof. Reading statement_timestamp() here and
        // subtracting the interval in SQL keeps BOTH the fixture value and
        // the production comparison anchored to the identical clock source.
        // The claim query below runs at a strictly-later
        // statement_timestamp(), so the row's effective age is 14:59 plus
        // the sub-second inter-statement delta — still strictly under the
        // 15-minute bound, deterministically.
        $anchor = $this->runWithFirmContext($firm, fn () => DB::selectOne('SELECT statement_timestamp() AS ts')->ts);

        $this->runWithFirmContext($firm, function () use ($event, $anchor) {
            DB::update(
                "UPDATE integration_outbox_events SET locked_at = ?::timestamptz - interval '14 minutes 59 seconds' WHERE id = ?",
                [$anchor, $event->id]
            );
        });

        $reclaim = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10));

        $this->assertCount(0, $reclaim, 'A lock younger than the 15-minute bound must not be reclaimed.');

        $unchanged = $this->runWithFirmContext($firm, fn () => DB::table('integration_outbox_events')->where('id', $event->id)->value('lock_token'));
        $this->assertSame($originalToken, $unchanged);
    }

    public function test_a_lock_at_exactly_15_minutes_1_second_is_reclaimed(): void
    {
        $firm = Firm::factory()->create();
        $event = IntegrationOutboxEvent::factory()
            ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
            ->create();

        $originalToken = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10))->first()->lock_token;

        $this->runWithFirmContext($firm, function () use ($event) {
            DB::table('integration_outbox_events')->where('id', $event->id)->update([
                'locked_at' => now()->subMinutes(15)->subSecond(),
            ]);
        });

        $reclaim = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10));

        $this->assertCount(1, $reclaim, 'A lock past the 15-minute bound must be reclaimed.');
        $this->assertNotSame($originalToken, $reclaim->first()->lock_token);
    }

    // ------------------------------------------------------------
    // SQL-shape assertion: single atomic UPDATE ... FOR UPDATE SKIP
    // LOCKED ... RETURNING, never a preceding bare SELECT.
    // ------------------------------------------------------------

    public function test_claim_executes_exactly_one_atomic_statement_with_no_preceding_bare_select(): void
    {
        $firm = Firm::factory()->create();
        IntegrationOutboxEvent::factory()
            ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
            ->create();

        $captured = [];
        DB::listen(function ($query) use (&$captured) {
            $captured[] = $query->sql;
        });

        $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10));

        $claimQueries = array_values(array_filter(
            $captured,
            fn (string $sql) => stripos($sql, 'integration_outbox_events') !== false
        ));

        $this->assertCount(1, $claimQueries, 'claim() must execute exactly one statement against integration_outbox_events.');

        $sql = $claimQueries[0];

        // claim() is now a single CTE-rewritten statement — WITH candidate
        // AS (... FOR UPDATE SKIP LOCKED) UPDATE ... WHERE id IN (SELECT id
        // FROM candidate) RETURNING * — fixing a Nested-Loop-Semi-Join
        // over-claiming bug in the earlier plain UPDATE ... WHERE id IN
        // (SELECT ...) form. The CTE's inner SELECT is part of this SAME
        // atomic statement, not a separate preceding bare SELECT, so it is
        // expected and fine; the single-statement-count assertion above is
        // what actually rules out a standalone SELECT executed first.
        $this->assertStringStartsWithIgnoringCase('with candidate as (', $sql);
        $this->assertStringContainsStringIgnoringCase('for update skip locked', $sql);
        $this->assertStringContainsStringIgnoringCase('returning', $sql);
        $this->assertStringContainsStringIgnoringCase('update integration_outbox_events', $sql, 'The single captured statement must contain the UPDATE clause itself, not just the CTE.');
        $this->assertStringContainsStringIgnoringCase('where id in (select id from candidate)', $sql, 'The UPDATE must target its rows via the CTE-embedded subquery, not a prior standalone SELECT.');
    }

    private function assertStringStartsWithIgnoringCase(string $needle, string $haystack): void
    {
        $this->assertSame(0, stripos(ltrim($haystack), $needle), "Expected \"{$haystack}\" to start with \"{$needle}\".");
    }
}
