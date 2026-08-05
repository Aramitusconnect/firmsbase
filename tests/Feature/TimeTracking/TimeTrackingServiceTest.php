<?php

namespace Tests\Feature\TimeTracking;

use App\Enums\TimeTrackingSessionStatus;
use App\Models\Firm;
use App\Models\User;
use App\Services\TimeTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TimeTrackingServiceTest extends TestCase
{
    use RefreshDatabase;

    private TimeTrackingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TimeTrackingService;
    }

    /**
     * Belt-and-suspenders alongside Laravel's own automatic
     * Carbon::setTestNow() reset (InteractsWithTestCaseLifecycle) — makes
     * the restoration explicit and independent of framework internals, per
     * the deterministic-time-test requirement that the clock is always
     * restored, not just implicitly reset by the base test case.
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_start_creates_an_active_session(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();

        $session = $this->service->start($firm, $user);

        $this->assertSame(TimeTrackingSessionStatus::Active, $session->status);
        $this->assertSame(0, $session->accumulated_seconds);
        $this->assertNotNull($session->last_resumed_at);
    }

    public function test_pause_accumulates_whole_seconds_and_clears_last_resumed_at(): void
    {
        // Freeze time before the session is created: now()->subSeconds(90)
        // below and the now() call inside TimeTrackingService::pause()
        // must resolve to the exact same instant, or a real-clock second
        // boundary crossed between the two calls makes this test flaky
        // (accumulated_seconds occasionally 91, not 90). Restored in
        // tearDown().
        $this->freezeTime();

        $firm = Firm::factory()->create();
        $user = User::factory()->create();

        $session = $this->service->start($firm, $user);
        $session->update(['last_resumed_at' => now()->subSeconds(90)]);

        $paused = $this->service->pause($session);

        $this->assertSame(TimeTrackingSessionStatus::Paused, $paused->status);
        $this->assertSame(90, $paused->accumulated_seconds);
        $this->assertIsInt($paused->accumulated_seconds);
        $this->assertNull($paused->last_resumed_at);
    }

    public function test_pause_throws_when_session_is_not_active(): void
    {
        // Section 39A-3L, Checkpoint 20 follow-up: time_tracking_sessions
        // is now FORCE RLS enabled. TimeTrackingService::pause() self-
        // wraps in its own runWithFirmContext() (see the service's own
        // docblock), which ALWAYS clears the PostgreSQL session/PHP-
        // memory tenant context again before returning (even on
        // success) — so the ->fresh() re-read below, which happens
        // AFTER pause() has already returned, would otherwise run with
        // no context active and be fail-closed (silently returning
        // null) under FORCE RLS. Re-querying under an explicit, scoped
        // runWithFirmContext() (rather than setting context for the
        // whole test class) is the narrow fix — pause() itself still
        // establishes its own context internally exactly as before.
        $firm = Firm::factory()->create();
        $user = User::factory()->create();
        $session = $this->service->start($firm, $user);
        $this->service->pause($session);

        $freshSession = $this->runWithFirmContext($firm, fn () => $session->fresh());

        $this->expectException(\RuntimeException::class);

        $this->service->pause($freshSession);
    }

    public function test_resume_reactivates_a_paused_session(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();
        $session = $this->service->start($firm, $user);
        $paused = $this->service->pause($session);

        $resumed = $this->service->resume($paused);

        $this->assertSame(TimeTrackingSessionStatus::Active, $resumed->status);
        $this->assertNotNull($resumed->last_resumed_at);
    }

    public function test_stop_creates_exactly_one_time_entry_with_whole_second_total(): void
    {
        // Section 39A-3L, Checkpoint 20 follow-up: same fail-closed-
        // fresh()-under-no-context reasoning as
        // test_pause_throws_when_session_is_not_active() above — stop()
        // clears its own internal context before returning, so both
        // ->fresh() re-reads below must be re-queried under an explicit,
        // scoped runWithFirmContext().
        //
        // Time frozen for the same reason as
        // test_pause_accumulates_whole_seconds_and_clears_last_resumed_at
        // above: now()->subSeconds(3600) and the now() call inside
        // TimeTrackingService::stop() must resolve to the exact same
        // instant. Restored in tearDown().
        $this->freezeTime();

        $firm = Firm::factory()->create();
        $user = User::factory()->create();
        $session = $this->service->start($firm, $user);
        $session->update(['last_resumed_at' => now()->subSeconds(3600)]);

        $entry = $this->service->stop($session);

        [$freshStatus, $freshTimeEntryCount] = $this->runWithFirmContext($firm, fn () => [
            $session->fresh()->status,
            $session->fresh()->timeEntry()->count(),
        ]);

        $this->assertSame(TimeTrackingSessionStatus::Stopped, $freshStatus);
        $this->assertSame(3600, $entry->seconds);
        $this->assertIsInt($entry->seconds);
        $this->assertSame($session->id, $entry->time_tracking_session_id);
        $this->assertSame(1, $freshTimeEntryCount);
    }

    public function test_stop_across_a_pause_resume_cycle_sums_whole_seconds_correctly(): void
    {
        // Section 39A-3L, Checkpoint 20 follow-up: same fail-closed-
        // fresh()-under-no-context reasoning as
        // test_pause_throws_when_session_is_not_active() above.
        //
        // Time frozen for the same reason as the other whole-second
        // assertions in this file: two separate now()->subSeconds(...)
        // offsets below must both resolve relative to one fixed instant,
        // not real wall-clock time (which could cross a second boundary
        // between either offset and the corresponding pause()/stop()
        // call). Restored in tearDown().
        $this->freezeTime();

        $firm = Firm::factory()->create();
        $user = User::factory()->create();

        $session = $this->service->start($firm, $user);
        $session->update(['last_resumed_at' => now()->subSeconds(600)]);
        $paused = $this->service->pause($session); // accumulated = 600

        $resumed = $this->service->resume($paused);

        // The update() call itself must also run under context (same
        // reasoning as above) — otherwise it would silently affect zero
        // rows under FORCE RLS while still mutating $resumed's in-memory
        // attributes, and the ->fresh() re-read immediately below would
        // then observe the OLD, unpersisted last_resumed_at instead.
        $freshResumed = $this->runWithFirmContext($firm, function () use ($resumed) {
            $resumed->update(['last_resumed_at' => now()->subSeconds(300)]);

            return $resumed->fresh();
        });

        $entry = $this->service->stop($freshResumed);

        $this->assertSame(900, $entry->seconds);
        $this->assertIsInt($entry->seconds);
    }

    public function test_stop_throws_when_already_stopped(): void
    {
        // Section 39A-3L, Checkpoint 20 follow-up: same fail-closed-
        // fresh()-under-no-context reasoning as
        // test_pause_throws_when_session_is_not_active() above.
        $firm = Firm::factory()->create();
        $user = User::factory()->create();
        $session = $this->service->start($firm, $user);
        $this->service->stop($session);

        $freshSession = $this->runWithFirmContext($firm, fn () => $session->fresh());

        $this->expectException(\RuntimeException::class);

        $this->service->stop($freshSession);
    }
}
