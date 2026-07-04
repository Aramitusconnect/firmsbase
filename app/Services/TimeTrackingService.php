<?php

namespace App\Services;

use App\Enums\TimeEntryStatus;
use App\Enums\TimeTrackingSessionStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\TimeEntry;
use App\Models\TimeTrackingSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * TimeTrackingService — the only place a TimeTrackingSession is
 * started/paused/resumed/stopped. Elapsed time is accumulated as a
 * whole-second integer (accumulated_seconds), never derived from
 * timestamp subtraction at read time — this is what makes "prevent
 * fractional idle/time values" actually true across any number of
 * pause/resume cycles. Stopping a session creates exactly one draft
 * TimeEntry from the session's total whole seconds.
 */
class TimeTrackingService
{
    public function start(
        Firm $firm,
        User $user,
        ?Matter $matter = null,
        ?Client $client = null,
        bool $isBillable = true,
        ?string $description = null,
    ): TimeTrackingSession {
        return TimeTrackingSession::create([
            'firm_id' => $firm->id,
            'user_id' => $user->id,
            'matter_id' => $matter?->id,
            'client_id' => $client?->id,
            'status' => TimeTrackingSessionStatus::Active,
            'started_at' => now(),
            'accumulated_seconds' => 0,
            'last_resumed_at' => now(),
            'is_billable' => $isBillable,
            'description' => $description,
        ]);
    }

    public function pause(TimeTrackingSession $session): TimeTrackingSession
    {
        if ($session->status !== TimeTrackingSessionStatus::Active) {
            throw new \RuntimeException('Only an active session can be paused.');
        }

        $session->update([
            'accumulated_seconds' => $session->accumulated_seconds + $this->elapsedSinceResume($session),
            'status' => TimeTrackingSessionStatus::Paused,
            'last_resumed_at' => null,
        ]);

        return $session->fresh();
    }

    public function resume(TimeTrackingSession $session): TimeTrackingSession
    {
        if ($session->status !== TimeTrackingSessionStatus::Paused) {
            throw new \RuntimeException('Only a paused session can be resumed.');
        }

        $session->update([
            'status' => TimeTrackingSessionStatus::Active,
            'last_resumed_at' => now(),
        ]);

        return $session->fresh();
    }

    /**
     * Stops the session and creates the one TimeEntry it generates, in
     * a single transaction.
     */
    public function stop(TimeTrackingSession $session): TimeEntry
    {
        if ($session->status === TimeTrackingSessionStatus::Stopped) {
            throw new \RuntimeException('Session is already stopped.');
        }

        return DB::transaction(function () use ($session) {
            $accumulated = $session->accumulated_seconds;

            if ($session->status === TimeTrackingSessionStatus::Active) {
                $accumulated += $this->elapsedSinceResume($session);
            }

            $session->update([
                'status' => TimeTrackingSessionStatus::Stopped,
                'accumulated_seconds' => $accumulated,
                'last_resumed_at' => null,
                'ended_at' => now(),
                'total_seconds' => $accumulated,
            ]);

            return TimeEntry::create([
                'firm_id' => $session->firm_id,
                'user_id' => $session->user_id,
                'matter_id' => $session->matter_id,
                'client_id' => $session->client_id,
                'time_tracking_session_id' => $session->id,
                'seconds' => $accumulated,
                'is_billable' => $session->is_billable,
                'description' => $session->description,
                'worked_on' => $session->started_at->toDateString(),
                'status' => TimeEntryStatus::Draft,
            ]);
        });
    }

    /**
     * diffInSeconds() returns a whole integer — this is the one place
     * timestamp subtraction happens, and it happens once per
     * pause/stop, immediately folded into the integer accumulator.
     */
    private function elapsedSinceResume(TimeTrackingSession $session): int
    {
        if (! $session->last_resumed_at) {
            return 0;
        }

        return $session->last_resumed_at->diffInSeconds(now());
    }
}
