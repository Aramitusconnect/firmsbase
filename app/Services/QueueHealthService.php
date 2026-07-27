<?php

namespace App\Services;

use Illuminate\Queue\Events\JobRetryRequested;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * QueueHealthService — read-only checks against Laravel's own
 * database queue tables (jobs/failed_jobs). No new table is created
 * for this (project rule: no extra tables outside the approved 15-
 * table Phase 4 data contract) — these are Laravel's standard queue
 * tables, already part of the framework, not a Phase 4 addition.
 * Platform-level, not tenant-scoped — a stuck queue affects every
 * firm, so this deliberately does not filter by firm_id.
 *
 * Phase 4 (FirmsVault Platform Admin Control Center, "Operations")
 * addition: pendingJobsCountByQueue()/exceptionSummary() are new,
 * narrow read helpers backing the Queues & Jobs admin page.
 * retryFailedJob()/deleteFailedJob() are new, thin plumbing
 * implementing the exact same semantics as Laravel's own built-in
 * `queue:retry {id}`/`queue:forget {id}` Artisan commands (see
 * vendor/laravel/framework/src/Illuminate/Queue/Console/RetryCommand.php
 * and ForgetFailedCommand.php) — both commands are themselves thin
 * wrappers around the container-bound `queue.failer`
 * (Illuminate\Queue\Failed\FailedJobProviderInterface) and `queue`
 * (Illuminate\Queue\QueueManager) services; this class reuses those
 * exact same container bindings rather than re-implementing failed-job
 * storage/dispatch from scratch, so behavior stays byte-for-byte
 * identical to running the Artisan commands directly. `$id` here is
 * the failed_jobs.uuid column value (config('queue.failed.driver')
 * defaults to 'database-uuids' in this application — see
 * config/queue.php — under which the failed-job provider keys every
 * lookup by uuid, not the bigint id).
 */
class QueueHealthService
{
    public function pendingJobsCount(string $queue = 'default'): int
    {
        return (int) DB::table('jobs')->where('queue', $queue)->count();
    }

    /**
     * Per-queue breakdown of the pendingJobsCount() total above —
     * every queue name currently present in `jobs`, mapped to its own
     * pending count. Ordered by queue name for deterministic display.
     *
     * @return array<string, int>
     */
    public function pendingJobsCountByQueue(): array
    {
        return DB::table('jobs')
            ->select('queue')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('queue')
            ->orderBy('queue')
            ->get()
            ->mapWithKeys(fn ($row): array => [$row->queue => (int) $row->aggregate])
            ->all();
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

    /**
     * Re-dispatches one failed job back onto its original queue,
     * exactly mirroring RetryCommand::retryJob()/resetAttempts(): the
     * payload's `attempts` counter (when present) is reset to 0 and
     * the raw payload is pushed back via the queue connection it
     * originally failed on, then the failed_jobs row is removed —
     * never a raw re-INSERT into `jobs`, so any connection-specific
     * push behavior (e.g. SQS message attributes) is preserved exactly
     * as the real `queue:retry` command would produce it.
     *
     * Returns false when no failed job matches $id (already retried/
     * deleted by someone else — TOCTOU-safe no-op, never an
     * exception).
     */
    public function retryFailedJob(string $id): bool
    {
        $failer = app('queue.failer');
        $job = $failer->find($id);

        if ($job === null) {
            return false;
        }

        event(new JobRetryRequested($job));

        $connection = app('queue')->connection($job->connection);
        $connection->pushRaw($this->resetAttempts($job->payload), $job->queue);

        $failer->forget($id);

        return true;
    }

    /**
     * Deletes one failed_jobs row, exactly mirroring
     * ForgetFailedCommand — never a real retry, purely a removal from
     * the failed-jobs table (the underlying job is not re-dispatched).
     */
    public function deleteFailedJob(string $id): bool
    {
        return app('queue.failer')->forget($id);
    }

    /**
     * A safe, bounded, single-line summary of a failed_jobs.exception
     * column — deliberately never the full stack trace (which could
     * embed sensitive request/job payload data verbatim in a
     * platform-admin-facing UI). Takes only the first line of the
     * exception text (conventionally "ExceptionClass: message" for a
     * Laravel-formatted trace) and truncates it.
     */
    public function exceptionSummary(?string $exception, int $maxLength = 200): string
    {
        if ($exception === null || trim($exception) === '') {
            return '—';
        }

        $firstLine = strtok($exception, "\n");
        $firstLine = $firstLine === false ? $exception : $firstLine;

        return Str::limit(trim($firstLine), $maxLength);
    }

    private function resetAttempts(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (is_array($decoded) && array_key_exists('attempts', $decoded)) {
            $decoded['attempts'] = 0;
        }

        return $decoded === null ? $payload : (json_encode($decoded) ?: $payload);
    }
}
