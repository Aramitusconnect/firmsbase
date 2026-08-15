<?php

namespace App\Services;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * SchedulerObservabilityService — reads Laravel's own registered
 * schedule and reports it honestly. Operations Control Plane
 * addition.
 *
 * THE DISTINCTION THIS CLASS EXISTS TO PRESERVE: a registered
 * schedule entry is a statement of intent. It is not evidence that
 * the command has ever run, succeeded, or will run. This codebase has
 * no scheduler run-history table, no per-command last-run timestamp,
 * and no failure log — so every execution-history field below is
 * reported as unavailable rather than back-filled from the cron
 * expression. A console that renders "Last run: 5 minutes ago"
 * computed from a cron string is describing a schedule, not a system.
 *
 * What CAN be reported truthfully:
 *   - the registered entries, read live from the Schedule object
 *     (never a hand-maintained list, so it cannot drift);
 *   - each entry's cron expression, timezone, overlap and
 *     single-server settings;
 *   - the NEXT expected run, which is a pure function of the cron
 *     expression and therefore a real property of the registration;
 *   - whether an overlap mutex is currently held, read from the
 *     mutex store the scheduler itself uses;
 *   - whether the scheduler process as a whole is alive, from
 *     SchedulerHealthService's heartbeat.
 *
 * What cannot, and is reported as such: last run, last success, last
 * failure, duration, consecutive failures, per-command history.
 */
class SchedulerObservabilityService
{
    public function __construct(private SchedulerHealthService $schedulerHealth) {}

    /**
     * True only if a durable, per-command execution history exists
     * somewhere in this application. It does not. Kept as a method
     * rather than a constant so the single place to flip when a real
     * history backend is added is obvious.
     */
    public function hasExecutionHistory(): bool
    {
        return false;
    }

    public function executionHistoryUnavailableReason(): string
    {
        return 'No scheduler run-history backend exists in this platform. Nothing records when a scheduled command '.
            'started, how long it took, or whether it succeeded, so last-run, last-success, last-failure, duration '.
            'and consecutive-failure counts cannot be shown for any entry below. Registration is not execution.';
    }

    /**
     * Every registered schedule entry, with the properties that are
     * genuinely knowable from the registration itself.
     *
     * Artisan::call('about') forces the console Application to
     * bootstrap first — the ->withSchedule() closure is registered via
     * Illuminate\Console\Application::starting(), which does not run
     * merely from a cold app(Schedule::class) resolution. This mirrors
     * the one existing precedent for this pattern in this codebase
     * (see OperationsScheduleRegistrationTest).
     *
     * @return array<int, array{command: string, description: ?string, expression: string, timezone: string, without_overlapping: bool, on_one_server: bool, next_run: ?string, lock_state: string}>
     */
    public function registeredEntries(): array
    {
        Artisan::call('about');

        return collect(app(Schedule::class)->events())
            ->map(fn (Event $event): array => [
                'command' => $this->commandName($event),
                'description' => $event->description,
                'expression' => $event->expression,
                'timezone' => $this->timezone($event),
                'without_overlapping' => (bool) $event->withoutOverlapping,
                'on_one_server' => (bool) $event->onOneServer,
                'next_run' => $this->nextRun($event),
                'lock_state' => $this->lockState($event),
            ])
            ->sortBy('command')
            ->values()
            ->all();
    }

    /**
     * Scheduler process liveness, from the heartbeat the scheduler
     * itself records every minute. This is the ONE genuinely
     * observable execution fact available: it proves the scheduler ran
     * recently, though it says nothing about any individual command's
     * outcome.
     *
     * @return array{observed: bool, healthy: bool, last_heartbeat_at: ?Carbon, age_seconds: ?int}
     */
    public function heartbeat(): array
    {
        $last = $this->schedulerHealth->lastHeartbeatAt();

        return [
            'observed' => $last !== null,
            'healthy' => $this->schedulerHealth->isHealthy(),
            'last_heartbeat_at' => $last === null ? null : Carbon::createFromTimestamp($last),
            'age_seconds' => $last === null ? null : max(0, now()->timestamp - $last),
        ];
    }

    /**
     * The overlap mutex state for one entry. Read through the event's
     * OWN mutex instance (Event::$mutex is public, and Schedule
     * injects the same instance it would use at run time — see
     * Schedule::__construct()), so this consults exactly the lock the
     * scheduler itself consults, including any non-default cache store
     * configured via Schedule::useCache(). Resolving an EventMutex
     * from the container instead would risk reading a different store,
     * and EventMutex is not even bound outside a scheduler run.
     *
     * "Held" means a run claimed the lock and has not released it —
     * which means either a run is genuinely in progress, or a previous
     * run died without releasing. This platform cannot tell those two
     * apart, so it reports the ambiguity rather than picking one. No
     * "Clear Lock" action is offered anywhere: clearing a live mutex
     * would allow a second concurrent execution of a command that
     * explicitly asked never to overlap.
     */
    private function lockState(Event $event): string
    {
        if (! $event->withoutOverlapping) {
            return 'Not Applicable';
        }

        $mutex = $event->mutex;

        if (! $mutex instanceof EventMutex) {
            return 'Unknown';
        }

        try {
            return $mutex->exists($event)
                ? 'Held (running, or a previous run did not release it)'
                : 'Not Held';
        } catch (\Throwable) {
            // A mutex store that cannot be reached is unknown, not free.
            return 'Unknown';
        }
    }

    private function nextRun(Event $event): ?string
    {
        try {
            return $event->nextRunDate()->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function timezone(Event $event): string
    {
        $timezone = $event->timezone;

        if ($timezone === null) {
            return config('app.timezone').' (application default)';
        }

        return (string) ($timezone instanceof \DateTimeZone ? $timezone->getName() : $timezone);
    }

    private function commandName(Event $event): string
    {
        $command = trim(str_replace(["'artisan'", 'artisan'], '', (string) ($event->command ?? '')));

        return $command === '' ? '(closure)' : $command;
    }
}
