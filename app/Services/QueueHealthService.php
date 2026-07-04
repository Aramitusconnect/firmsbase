<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * QueueHealthService — read-only checks against Laravel's own
 * database queue tables (jobs/failed_jobs). No new table is created
 * for this (project rule: no extra tables outside the approved 15-
 * table Phase 4 data contract) — these are Laravel's standard queue
 * tables, already part of the framework, not a Phase 4 addition.
 * Platform-level, not tenant-scoped — a stuck queue affects every
 * firm, so this deliberately does not filter by firm_id.
 */
class QueueHealthService
{
    public function pendingJobsCount(string $queue = 'default'): int
    {
        return (int) DB::table('jobs')->where('queue', $queue)->count();
    }

    public function failedJobsCount(): int
    {
        return (int) DB::table('failed_jobs')->count();
    }

    /**
     * Age, in seconds, of the oldest still-pending job on the queue.
     * Null when the queue is empty. Laravel's `jobs` table stores
     * created_at as a raw unix timestamp integer, not a datetime
     * column — read accordingly.
     */
    public function oldestPendingJobAgeSeconds(string $queue = 'default'): ?int
    {
        $oldestCreatedAt = DB::table('jobs')
            ->where('queue', $queue)
            ->min('created_at');

        if ($oldestCreatedAt === null) {
            return null;
        }

        return max(0, now()->timestamp - (int) $oldestCreatedAt);
    }

    /**
     * Pure threshold check — no alerting/paging (out of phase). A
     * caller (future ops dashboard/command) decides what to do with
     * an unhealthy result.
     */
    public function isHealthy(
        string $queue = 'default',
        int $maxPendingCount = 500,
        int $maxFailedCount = 50,
        int $maxOldestPendingAgeSeconds = 900,
    ): bool {
        if ($this->pendingJobsCount($queue) > $maxPendingCount) {
            return false;
        }

        if ($this->failedJobsCount() > $maxFailedCount) {
            return false;
        }

        $oldestAge = $this->oldestPendingJobAgeSeconds($queue);

        if ($oldestAge !== null && $oldestAge > $maxOldestPendingAgeSeconds) {
            return false;
        }

        return true;
    }
}
