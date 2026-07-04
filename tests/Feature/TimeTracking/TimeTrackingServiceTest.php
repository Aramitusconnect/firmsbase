<?php

namespace Tests\Feature\TimeTracking;

use App\Enums\TimeTrackingSessionStatus;
use App\Models\Firm;
use App\Models\User;
use App\Services\TimeTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimeTrackingServiceTest extends TestCase
{
    use RefreshDatabase;

    private TimeTrackingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TimeTrackingService();
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
        $firm = Firm::factory()->create();
        $user = User::factory()->create();
        $session = $this->service->start($firm, $user);
        $this->service->pause($session);

        $this->expectException(\RuntimeException::class);

        $this->service->pause($session->fresh());
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
        $firm = Firm::factory()->create();
        $user = User::factory()->create();
        $session = $this->service->start($firm, $user);
        $session->update(['last_resumed_at' => now()->subSeconds(3600)]);

        $entry = $this->service->stop($session);

        $this->assertSame(TimeTrackingSessionStatus::Stopped, $session->fresh()->status);
        $this->assertSame(3600, $entry->seconds);
        $this->assertIsInt($entry->seconds);
        $this->assertSame($session->id, $entry->time_tracking_session_id);
        $this->assertSame(1, $session->fresh()->timeEntry()->count());
    }

    public function test_stop_across_a_pause_resume_cycle_sums_whole_seconds_correctly(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();

        $session = $this->service->start($firm, $user);
        $session->update(['last_resumed_at' => now()->subSeconds(600)]);
        $paused = $this->service->pause($session); // accumulated = 600

        $resumed = $this->service->resume($paused);
        $resumed->update(['last_resumed_at' => now()->subSeconds(300)]);

        $entry = $this->service->stop($resumed->fresh());

        $this->assertSame(900, $entry->seconds);
        $this->assertIsInt($entry->seconds);
    }

    public function test_stop_throws_when_already_stopped(): void
    {
        $firm = Firm::factory()->create();
        $user = User::factory()->create();
        $session = $this->service->start($firm, $user);
        $this->service->stop($session);

        $this->expectException(\RuntimeException::class);

        $this->service->stop($session->fresh());
    }
}
