<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformAdmin;

use App\Jobs\RunHealthChecksJob;
use App\Services\SchedulerHealthService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * OperationsScheduleRegistrationTest — Phase 4 (FirmsVault Platform
 * Admin Control Center, "Operations"). Proves bootstrap/app.php's two
 * new ->withSchedule() entries (health:checks:run,
 * scheduler:heartbeat:record) are registered with the documented
 * cadence, mirroring
 * PlatformIntegrationProviderHealthSummaryTest::test_the_command_is_scheduled_every_five_minutes_without_overlapping()'s
 * exact pattern — the only existing schedule-registration test found
 * in this codebase.
 */
final class OperationsScheduleRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_checks_run_is_scheduled_every_five_minutes_without_overlapping(): void
    {
        // bootstrap/app.php's ->withSchedule() callback is registered
        // via Illuminate\Console\Application::starting(), which only
        // actually runs once a genuine console Application is
        // bootstrapped — Artisan::call() forces that synchronously.
        Artisan::call('about');

        $schedule = app(Schedule::class);

        $event = collect($schedule->events())
            ->first(fn ($event) => str_contains($event->command ?? '', 'health:checks:run'));

        $this->assertNotNull($event, 'health:checks:run must be scheduled in bootstrap/app.php.');
        $this->assertSame('*/5 * * * *', $event->expression, 'Must run every five minutes.');
        $this->assertTrue($event->withoutOverlapping, 'Must be registered ->withoutOverlapping().');
    }

    public function test_scheduler_heartbeat_record_is_scheduled_every_minute_without_overlapping(): void
    {
        Artisan::call('about');

        $schedule = app(Schedule::class);

        $event = collect($schedule->events())
            ->first(fn ($event) => str_contains($event->command ?? '', 'scheduler:heartbeat:record'));

        $this->assertNotNull($event, 'scheduler:heartbeat:record must be scheduled in bootstrap/app.php.');
        $this->assertSame('* * * * *', $event->expression, 'Must run every minute.');
        $this->assertTrue($event->withoutOverlapping, 'Must be registered ->withoutOverlapping().');
    }

    public function test_health_checks_run_command_dispatches_the_job(): void
    {
        Queue::fake();

        Artisan::call('health:checks:run');

        Queue::assertPushed(RunHealthChecksJob::class);
    }

    public function test_scheduler_heartbeat_record_command_records_a_heartbeat(): void
    {
        Cache::forget('firmsbase:scheduler:last_heartbeat_at');

        $healthService = app(SchedulerHealthService::class);
        $this->assertFalse($healthService->isHealthy());

        Artisan::call('scheduler:heartbeat:record');

        $this->assertTrue($healthService->isHealthy());
        $this->assertNotNull($healthService->lastHeartbeatAt());
    }
}
