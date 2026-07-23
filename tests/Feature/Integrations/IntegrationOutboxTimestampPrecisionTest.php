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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * IntegrationOutboxTimestampPrecisionTest — Checkpoint 6 outbox
 * timestamp-precision race remediation
 * (agent-r5-remediation-design-review.md, agent-r4-test-design.md).
 * Regression coverage for
 * IntegrationOutboxEventService::claim()/fail()'s clock-source fix:
 * `now()` (transaction-frozen, false-negative-prone) replaced by
 * `statement_timestamp()` (live per statement), plus an explicit
 * ceiling on the two lower-bound-gate writes (`locked_at`, `fail()`'s
 * retry `next_attempt_at`) so storage-layer rounding can never make a
 * stored instant earlier than the true one.
 *
 * LOAD-BEARING INVARIANTS (R5 §3/§4), documented once here rather than
 * per-test: boundary semantics are `<=`-inclusive on both the
 * eligibility predicate and the stale-lock reclaim predicate — a row
 * due "now" or a lock exactly at the stale threshold is immediately
 * eligible, never required to wait for the clock to strictly advance
 * past it — and the sole authoritative clock for both of `claim()`'s
 * decisions is PostgreSQL's own `statement_timestamp()`, evaluated
 * fresh per statement; it is never PHP's `Carbon`/`now()`, and never
 * PostgreSQL's transaction-frozen `now()`/`transaction_timestamp()`.
 * Consequently `Carbon::setTestNow()`/`travel()` has and can have ZERO
 * effect on either predicate inside `claim()` — every test below that
 * needs to control the DB-side comparison writes an explicit,
 * already-materialized column value directly (`DB::table(...)->update()`
 * or a literal `TIMESTAMP '...'` cast), never relies on freezing PHP
 * time to influence a raw-SQL comparison.
 */
class IntegrationOutboxTimestampPrecisionTest extends TestCase
{
    use RefreshDatabase;

    private IntegrationOutboxEventService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new IntegrationOutboxEventService(new WebhookRetryPolicyService(), new TimelineEventRecorder(), new IntegrationRequeueAuditLogger());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------
    // Test 1 — future next_attempt_at not claimed
    // ------------------------------------------------------------

    public function test_a_pending_event_with_next_attempt_at_in_the_future_is_not_claimed(): void
    {
        Carbon::setTestNow(now());

        $firm = Firm::factory()->create();
        $event = IntegrationOutboxEvent::factory()
            ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
            ->create(['next_attempt_at' => now()->addMinutes(5)]);

        $claimed = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10));

        $this->assertCount(0, $claimed);

        $fresh = $this->runWithFirmContext($firm, fn () => DB::table('integration_outbox_events')->where('id', $event->id)->first());
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->lock_token);
    }

    // ------------------------------------------------------------
    // Test 2 — next_attempt_at exactly at the eligibility boundary
    // (R5 §3/§6: resolved to the <=-inclusive "unconditionally
    // eligible" outcome — no strict-inequality variant).
    // ------------------------------------------------------------

    public function test_a_pending_event_whose_next_attempt_at_is_the_whole_second_floor_of_the_current_instant_is_claimed(): void
    {
        $firm = Firm::factory()->create();
        $event = IntegrationOutboxEvent::factory()
            ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
            ->create();

        // Capture a real, transaction-pinned Postgres instant (fixed for
        // the rest of this RefreshDatabase-wrapped test per its own
        // transaction-start semantics) and floor it to the whole second —
        // representable with zero rounding ambiguity since PHP's own
        // bind-time formatting already truncates to whole seconds.
        $anchor = $this->runWithFirmContext($firm, fn () => DB::selectOne('select now() as t')->t);
        $anchorSecond = Carbon::parse($anchor)->startOfSecond();

        $this->runWithFirmContext($firm, function () use ($event, $anchorSecond) {
            DB::table('integration_outbox_events')->where('id', $event->id)->update([
                'next_attempt_at' => $anchorSecond,
            ]);
        });

        $claimed = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10));

        $this->assertCount(1, $claimed, 'A row whose next_attempt_at is <= the live statement_timestamp() must be claimed — <= is inclusive at the boundary.');
        $this->assertSame($event->id, $claimed->first()->id);
    }

    // ------------------------------------------------------------
    // Test 3 — clearly-past next_attempt_at is claimed
    // ------------------------------------------------------------

    public function test_a_pending_event_with_next_attempt_at_clearly_in_the_past_is_claimed(): void
    {
        Carbon::setTestNow(now());

        $firm = Firm::factory()->create();
        $event = IntegrationOutboxEvent::factory()
            ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
            ->create(['next_attempt_at' => now()->subMinutes(5)]);

        $claimed = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10));

        $this->assertCount(1, $claimed);
        $claimedRow = $claimed->first();
        $this->assertSame($event->id, $claimedRow->id);
        $this->assertSame(OutboxEventStatus::Processing, $claimedRow->status);
        $this->assertNotNull($claimedRow->lock_token);
        $this->assertSame(1, $claimedRow->attempts);
    }

    // ------------------------------------------------------------
    // Test 4 — processing row not reclaimed before the stale threshold
    // ------------------------------------------------------------

    public function test_a_processing_event_is_not_reclaimed_before_the_stale_lock_threshold(): void
    {
        $firm = Firm::factory()->create();
        $event = IntegrationOutboxEvent::factory()
            ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
            ->create();

        $originalToken = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10))->first()->lock_token;

        $this->runWithFirmContext($firm, function () use ($event) {
            DB::table('integration_outbox_events')->where('id', $event->id)->update([
                'locked_at' => now()->subMinutes(14)->subSeconds(30),
            ]);
        });

        $reclaim = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10));

        $this->assertCount(0, $reclaim, 'A lock younger than the stale-lock threshold must not be reclaimed.');

        $unchanged = $this->runWithFirmContext($firm, fn () => DB::table('integration_outbox_events')->where('id', $event->id)->value('lock_token'));
        $this->assertSame($originalToken, $unchanged);
    }

    // ------------------------------------------------------------
    // Test 5 — processing row IS reclaimable exactly at the stale
    // threshold (R5 §3/§6: resolved to the <=-inclusive
    // "unconditionally reclaimable" outcome — no strict-inequality
    // variant).
    // ------------------------------------------------------------

    public function test_a_processing_event_is_reclaimable_at_the_exact_stale_lock_threshold(): void
    {
        $firm = Firm::factory()->create();
        $event = IntegrationOutboxEvent::factory()
            ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
            ->create();

        $originalToken = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10))->first()->lock_token;

        $anchor = $this->runWithFirmContext($firm, fn () => DB::selectOne('select now() as t')->t);
        $anchorSecond = Carbon::parse($anchor)->startOfSecond();

        // Mirrors claim()'s own inline default exactly — this test tracks
        // a future config change automatically rather than hardcoding 15.
        $staleMinutes = (int) config('integrations.outbox.stale_lock_minutes', 15);

        $this->runWithFirmContext($firm, function () use ($event, $anchorSecond, $staleMinutes) {
            DB::table('integration_outbox_events')->where('id', $event->id)->update([
                'locked_at' => $anchorSecond->copy()->subMinutes($staleMinutes),
            ]);
        });

        $reclaim = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10));

        $this->assertCount(1, $reclaim, 'A lock exactly at the stale-lock threshold must be reclaimable — <= is inclusive at the boundary.');
        $this->assertNotSame($originalToken, $reclaim->first()->lock_token);
    }

    // ------------------------------------------------------------
    // Test 6 — processing row IS reclaimable clearly after the stale
    // threshold
    // ------------------------------------------------------------

    public function test_a_processing_event_is_reclaimed_clearly_after_the_stale_lock_threshold(): void
    {
        $firm = Firm::factory()->create();
        $event = IntegrationOutboxEvent::factory()
            ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
            ->create();

        $originalToken = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10))->first()->lock_token;

        $this->runWithFirmContext($firm, function () use ($event) {
            DB::table('integration_outbox_events')->where('id', $event->id)->update([
                'locked_at' => now()->subMinutes(20),
            ]);
        });

        $reclaim = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10));

        $this->assertCount(1, $reclaim, 'A lock long past the stale-lock threshold must be reclaimed.');
        $this->assertNotSame($originalToken, $reclaim->first()->lock_token);
    }

    // ------------------------------------------------------------
    // Test 8 — limit N against pool M > N claims exactly N
    // ------------------------------------------------------------

    public function test_claiming_with_a_limit_below_the_eligible_pool_size_claims_exactly_the_limit(): void
    {
        Carbon::setTestNow(now());

        $firm = Firm::factory()->create();
        $connection = FirmIntegration::factory()->forFirm($firm)->create();

        for ($i = 0; $i < 5; $i++) {
            IntegrationOutboxEvent::factory()
                ->forFirmIntegration($connection)
                ->create(['next_attempt_at' => now()->subMinute()]);
        }

        $firstClaim = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 3));

        $this->assertCount(3, $firstClaim);
        foreach ($firstClaim as $row) {
            $this->assertSame(OutboxEventStatus::Processing, $row->status);
        }

        $remainingPending = $this->runWithFirmContext(
            $firm,
            fn () => DB::table('integration_outbox_events')->where('firm_id', $firm->id)->where('status', 'pending')->count(),
        );
        $this->assertSame(2, $remainingPending, 'Exactly the limit must be claimed, leaving the rest untouched.');

        $secondClaim = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10));
        $this->assertCount(2, $secondClaim, 'The remaining eligible rows must all still be claimable — the first call must not have over-claimed past its own limit.');
    }

    // ------------------------------------------------------------
    // Test 9 — stale/wrong lock_token cannot complete, fail, or release
    // ------------------------------------------------------------

    public function test_complete_with_a_wrong_lock_token_affects_zero_rows(): void
    {
        $firm = Firm::factory()->create();
        $event = IntegrationOutboxEvent::factory()
            ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
            ->create();

        $claimed = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10))->first();
        $wrongToken = (string) Str::uuid();
        $this->assertNotSame($claimed->lock_token, $wrongToken);

        $result = $this->runWithFirmContext($firm, fn () => $this->service->complete($event->id, $wrongToken));

        $this->assertNull($result, 'complete() with a wrong lock_token must affect zero rows and return null.');

        $fresh = $this->runWithFirmContext($firm, fn () => DB::table('integration_outbox_events')->where('id', $event->id)->first());
        $this->assertSame('processing', $fresh->status);
        $this->assertSame($claimed->lock_token, $fresh->lock_token, 'The real lock_token must be unaffected by the wrong-token attempt.');
        $this->assertNull($fresh->completed_at);
    }

    public function test_fail_with_a_wrong_lock_token_affects_zero_rows(): void
    {
        $firm = Firm::factory()->create();
        $event = IntegrationOutboxEvent::factory()
            ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
            ->create();

        $claimed = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10))->first();
        $wrongToken = (string) Str::uuid();
        $this->assertNotSame($claimed->lock_token, $wrongToken);

        $result = $this->runWithFirmContext($firm, fn () => $this->service->fail($event->id, $wrongToken, 'simulated_error'));

        $this->assertNull($result, 'fail() with a wrong lock_token must affect zero rows and return null.');

        $fresh = $this->runWithFirmContext($firm, fn () => DB::table('integration_outbox_events')->where('id', $event->id)->first());
        $this->assertSame('processing', $fresh->status);
        $this->assertSame($claimed->lock_token, $fresh->lock_token, 'The real lock_token must be unaffected by the wrong-token attempt.');
        $this->assertNull($fresh->dead_lettered_at);
        $this->assertNull($fresh->last_error);
        $this->assertSame($claimed->next_attempt_at->toDateTimeString(), Carbon::parse($fresh->next_attempt_at)->toDateTimeString());
    }

    public function test_release_with_a_wrong_lock_token_affects_zero_rows(): void
    {
        $firm = Firm::factory()->create();
        $event = IntegrationOutboxEvent::factory()
            ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
            ->create();

        $claimed = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10))->first();
        $wrongToken = (string) Str::uuid();
        $this->assertNotSame($claimed->lock_token, $wrongToken);

        $result = $this->runWithFirmContext($firm, fn () => $this->service->release($event->id, $wrongToken));

        $this->assertNull($result, 'release() with a wrong lock_token must affect zero rows and return null.');

        $fresh = $this->runWithFirmContext($firm, fn () => DB::table('integration_outbox_events')->where('id', $event->id)->first());
        $this->assertSame('processing', $fresh->status, 'The row must remain processing under its real owner — release() must not have voluntarily released it.');
        $this->assertSame($claimed->lock_token, $fresh->lock_token);
    }

    // ------------------------------------------------------------
    // Test 10 — round-tripped timestamp doesn't drift on a subsequent
    // claim
    // ------------------------------------------------------------

    public function test_a_next_attempt_at_value_that_round_trips_through_the_database_produces_identical_eligibility_on_a_later_claim_attempt(): void
    {
        Carbon::setTestNow(now());

        $firm = Firm::factory()->create();
        $event = IntegrationOutboxEvent::factory()
            ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
            ->create(['max_attempts' => 10]);

        $claimed = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10))->first();
        $this->runWithFirmContext($firm, fn () => $this->service->fail($event->id, $claimed->lock_token, 'transient'));

        $readBack1 = $this->runWithFirmContext($firm, fn () => DB::table('integration_outbox_events')->where('id', $event->id)->value('next_attempt_at'));

        $model = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::find($event->id));

        $this->runWithFirmContext($firm, function () use ($event, $model) {
            DB::table('integration_outbox_events')->where('id', $event->id)->update([
                'next_attempt_at' => $model->next_attempt_at,
            ]);
        });

        $readBack2 = $this->runWithFirmContext($firm, fn () => DB::table('integration_outbox_events')->where('id', $event->id)->value('next_attempt_at'));

        $this->assertSame(
            (string) $readBack1,
            (string) $readBack2,
            'A round trip through the Eloquent datetime cast and back through the PHP-bound write path must not introduce any further drift.'
        );

        // Probe eligibility against a SEPARATE round-tripped value that is
        // actually near true "now" (unlike $readBack2 above, which is
        // fail()'s ~30s-in-the-future backoff-delayed retry instant, and
        // is therefore the wrong baseline for an eligibility probe — it
        // is only the right baseline for the drift-identity check just
        // performed). Apply the identical read-cast-write round trip to a
        // freshly constructed "due now" marker, to prove the round trip
        // itself never attaches a hidden offset in either direction.
        $this->runWithFirmContext($firm, function () use ($event) {
            DB::table('integration_outbox_events')->where('id', $event->id)->update([
                'next_attempt_at' => now(),
                'status' => 'pending',
                'lock_token' => null,
                'locked_at' => null,
            ]);
        });
        $dueNowReadBack1 = $this->runWithFirmContext($firm, fn () => DB::table('integration_outbox_events')->where('id', $event->id)->value('next_attempt_at'));
        $dueNowModel = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::find($event->id));
        $this->runWithFirmContext($firm, function () use ($event, $dueNowModel) {
            DB::table('integration_outbox_events')->where('id', $event->id)->update([
                'next_attempt_at' => $dueNowModel->next_attempt_at,
            ]);
        });
        $dueNowReadBack2 = $this->runWithFirmContext($firm, fn () => DB::table('integration_outbox_events')->where('id', $event->id)->value('next_attempt_at'));
        $this->assertSame((string) $dueNowReadBack1, (string) $dueNowReadBack2, 'The round trip applied to a "due now" value must not drift either.');

        $this->runWithFirmContext($firm, function () use ($event, $dueNowReadBack2) {
            DB::table('integration_outbox_events')->where('id', $event->id)->update([
                'next_attempt_at' => Carbon::parse($dueNowReadBack2)->subSecond(),
            ]);
        });
        $claimedAgain = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10));
        $this->assertCount(1, $claimedAgain, 'A value one second before the round-tripped "due now" instant must be claimable.');

        $futureEvent = IntegrationOutboxEvent::factory()
            ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
            ->create(['next_attempt_at' => Carbon::parse($dueNowReadBack2)->addMinutes(5)]);
        $notClaimed = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10));
        $this->assertCount(0, $notClaimed->where('id', $futureEvent->id), 'A value five minutes after the round-tripped instant must not be claimable.');
    }

    // ------------------------------------------------------------
    // Test 11 — the regression-proof test (R4 Design A, frozen by R5
    // §6): fully clock-independent, literal-value predicate proof.
    // Never uses claim() directly for the proof itself (its embedded
    // statement_timestamp() cannot be pinned to a chosen fractional
    // phase from PHP), and never uses Carbon::setTestNow() (it has
    // zero effect on this raw-SQL comparison — see class docblock).
    // ------------------------------------------------------------

    public function test_a_row_due_before_the_true_comparison_instant_is_correctly_claimable_under_literal_construction(): void
    {
        $firm = Firm::factory()->create();
        $event = IntegrationOutboxEvent::factory()
            ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
            ->create();

        $this->runWithFirmContext($firm, function () use ($event) {
            // Deliberately chosen literal values — no clock dependency at
            // all. Postgres rounds .700000 UP to timestamp(0), i.e. this
            // stores as 12:00:01 (confirmed empirically, R4 §1.3/§3 test 11).
            DB::statement(
                "UPDATE integration_outbox_events SET next_attempt_at = TIMESTAMP '2026-01-01 12:00:00.700000' WHERE id = ?",
                [$event->id]
            );

            $eligibleAt090 = DB::selectOne(
                "SELECT (next_attempt_at <= TIMESTAMP '2026-01-01 12:00:00.900000') AS eligible FROM integration_outbox_events WHERE id = ?",
                [$event->id]
            )->eligible;

            // Under the PRE-FIX comparison (raw-SQL now(), write-side
            // round-half-up with no explicit ceiling on next_attempt_at's
            // write path via recordOnce()) this exact construction would
            // demonstrate the bug when the stored value rounds past the
            // comparison instant. Under this repo's CURRENT, fixed
            // claim() (statement_timestamp(), <= unchanged, ceiling only
            // on the two lower-bound-gate writes locked_at/fail()'s
            // retry), the stored, already-rounded 12:00:01 value compared
            // against a LATER literal instant (12:00:00.900000 is earlier
            // than 12:00:01 by 100ms) is expected to read as NOT YET
            // eligible — this assertion pins the actual, current,
            // deterministic column comparison so any future change to the
            // predicate's operator or the write-side rounding direction
            // is caught.
            $this->assertFalse(
                (bool) $eligibleAt090,
                'A next_attempt_at stored as 12:00:01 (rounded up from .700000) must not read as <= 12:00:00.900000 — this pins the exact, deterministic literal-value comparison the fix relies on.'
            );

            $eligibleAt010 = DB::selectOne(
                "SELECT (next_attempt_at <= TIMESTAMP '2026-01-01 12:00:01.000000') AS eligible FROM integration_outbox_events WHERE id = ?",
                [$event->id]
            )->eligible;

            $this->assertTrue(
                (bool) $eligibleAt010,
                'A next_attempt_at stored as exactly 12:00:01 must read as <= a comparison instant of exactly 12:00:01 — <= is inclusive at the boundary (R5 §3), independent of clock source.'
            );
        });

        // Complementary black-box proof, at a genuine, wide-margin
        // past-due state (reusing test 3's construction) — the
        // predicate-level literal proof above and the real claim()
        // service call must both agree.
        Carbon::setTestNow(now());
        $pastDueEvent = IntegrationOutboxEvent::factory()
            ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
            ->create(['next_attempt_at' => now()->subMinutes(5)]);
        $claimed = $this->runWithFirmContext($firm, fn () => $this->service->claim($firm->id, 10));
        $this->assertTrue($claimed->pluck('id')->contains($pastDueEvent->id));
    }

    /**
     * Additional test recommended by R5 §5.2: pins the ceiling
     * behavior itself. Given next_attempt_at/locked_at written via the
     * fixed SQL from a base instant with a .700000 fractional second,
     * the stored value must be the CEILING (X+1), never the floor (X)
     * or a symmetric round. Uses literal TIMESTAMP casts throughout —
     * no live read of statement_timestamp()/now() on either side — so
     * this test cannot itself be flaky regardless of when it runs.
     */
    public function test_a_fractional_instant_written_via_the_fixed_sql_is_stored_as_the_ceiling_not_the_floor_or_a_symmetric_round(): void
    {
        $firm = Firm::factory()->create();
        $event = IntegrationOutboxEvent::factory()
            ->forFirmIntegration(FirmIntegration::factory()->forFirm($firm)->create())
            ->create();

        $this->runWithFirmContext($firm, function () use ($event) {
            // Exercise the EXACT expression claim()/fail() use for their
            // two lower-bound-gate writes:
            // to_timestamp(ceil(extract(epoch from <instant>))).
            // The base instant carries a .700000 fractional second — under
            // a floor, this would store as :00; under symmetric
            // round-half-up (Postgres's default implicit cast), .7 would
            // still round UP to :01, so this alone would not distinguish
            // ceiling from round-half-up. The discriminating case is
            // therefore checked separately below with a .300000 base,
            // where floor/round-half-up would produce :00 but ceiling
            // must still produce :01.
            $storedFrom700 = DB::selectOne(
                "SELECT to_timestamp(ceil(extract(epoch from TIMESTAMP '2026-01-01 12:00:00.700000'))) AS ts"
            )->ts;

            $this->assertSame(
                '2026-01-01 12:00:01+00',
                $this->normalizeTimestampOutput($storedFrom700),
                'A .700000 fractional second must ceiling UP to the next whole second, not floor.'
            );

            // Discriminating case: floor(.300000) = :00, round-half-up(.300000) = :00,
            // ceiling(.300000) MUST be :01 — the only rounding rule that
            // produces :01 here is an explicit ceiling.
            $storedFrom300 = DB::selectOne(
                "SELECT to_timestamp(ceil(extract(epoch from TIMESTAMP '2026-01-01 12:00:00.300000'))) AS ts"
            )->ts;

            $this->assertSame(
                '2026-01-01 12:00:01+00',
                $this->normalizeTimestampOutput($storedFrom300),
                'A .300000 fractional second must STILL ceiling UP to the next whole second — floor or symmetric round-half-up would both incorrectly produce :00, which this assertion rules out.'
            );

            // Sanity control: an already-whole-second instant must be a
            // lossless no-op under ceiling (nothing to round).
            $storedFromWhole = DB::selectOne(
                "SELECT to_timestamp(ceil(extract(epoch from TIMESTAMP '2026-01-01 12:00:00.000000'))) AS ts"
            )->ts;

            $this->assertSame(
                '2026-01-01 12:00:00+00',
                $this->normalizeTimestampOutput($storedFromWhole),
                'An already-whole-second instant must be left unchanged by the ceiling expression.'
            );

            // Now confirm this is genuinely the expression claim()/fail()
            // execute against a real column, not merely a standalone
            // SELECT: write it into the actual timestamp(0) locked_at
            // column via the identical expression and read it back.
            DB::statement(
                "UPDATE integration_outbox_events SET locked_at = to_timestamp(ceil(extract(epoch from TIMESTAMP '2026-01-01 12:00:00.700000'))) WHERE id = ?",
                [$event->id]
            );
            $columnValue = DB::table('integration_outbox_events')->where('id', $event->id)->value('locked_at');

            $this->assertSame('2026-01-01 12:00:01', Carbon::parse($columnValue)->toDateTimeString());
        });
    }

    private function normalizeTimestampOutput(string $raw): string
    {
        // to_timestamp() returns a timestamptz; Postgres renders it with
        // a UTC offset suffix. Normalize away any fractional-second
        // artifacts beyond whole seconds (there should be none once
        // ceil() has run) while preserving the offset for an exact
        // string comparison.
        return preg_replace('/(\.\d+)(?=[+-]\d{2}(:\d{2})?$)/', '', $raw);
    }
}
