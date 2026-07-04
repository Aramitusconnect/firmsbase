<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * SchedulerHealthService — tracks whether Laravel's scheduler is
 * actually running, WITHOUT a new database table (project rule: no
 * extra tables outside the approved 15-table Phase 4 data contract).
 * A scheduled command (wired up outside this phase's scope — no
 * app/Console/Kernel.php or routes/console.php schedule entries are
 * added here, since that would be a scheduler REGISTRATION, not this
 * health-check abstraction) calls recordHeartbeat() every run;
 * isHealthy() reports whether a heartbeat has been seen recently
 * enough. Uses the framework cache, not a bespoke table — the same
 * pattern commonly used for scheduler heartbeats.
 */
class SchedulerHealthService
{
    private const CACHE_KEY = 'firmsbase:scheduler:last_heartbeat_at';

    public function recordHeartbeat(): void
    {
        Cache::put(self::CACHE_KEY, now()->timestamp, now()->addDay());
    }

    public function lastHeartbeatAt(): ?int
    {
        return Cache::get(self::CACHE_KEY);
    }

    /**
     * Healthy when a heartbeat has been recorded within the last
     * $maxAgeSeconds (default 5 minutes — comfortably more than one
     * scheduler tick even if a run is briefly delayed).
     */
    public function isHealthy(int $maxAgeSeconds = 300): bool
    {
        $last = $this->lastHeartbeatAt();

        if ($last === null) {
            return false;
        }

        return (now()->timestamp - $last) <= $maxAgeSeconds;
    }
}
