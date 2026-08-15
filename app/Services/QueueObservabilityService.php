<?php

namespace App\Services;

use App\ValueObjects\QueueObservation;
use Illuminate\Support\Facades\DB;

/**
 * QueueObservabilityService — per-queue operational metrics read
 * directly from Laravel's own database queue tables. Operations
 * Control Plane addition, additive: it CONSUMES QueueHealthService
 * (retry/forget/threshold semantics stay there, unchanged) and adds
 * only the per-queue breakdown the Queues & Jobs console needs.
 *
 * `jobs`/`failed_jobs` carry no RLS at all (System/global — see
 * QueueHealthService's own docblock), so nothing here needs tenant
 * context.
 *
 * WHAT THIS CAN AND CANNOT SEE — the distinction the whole Operations
 * mission turns on:
 *
 *   CAN: pending, reserved (in-flight), delayed, failed, and the age
 *        of the oldest pending/failed job, per queue. All of these
 *        are real rows with real timestamps.
 *
 *   CANNOT: whether any worker process is alive, how many workers are
 *        expected, or how many jobs were processed recently. The
 *        database queue driver maintains no worker registry, no
 *        heartbeat, and no processed counter — there is simply
 *        nothing to read. Those questions are answered by
 *        workerEvidence() with an explicit "not monitored", never by
 *        inferring liveness from queue depth.
 *
 * The inference this class refuses to make is worth naming: an empty
 * queue is exactly what a healthy idle system AND a system whose
 * workers all died look like. Reporting "0 pending, healthy" for the
 * second case is how an outage goes unnoticed for hours.
 */
class QueueObservabilityService
{
    /**
     * Backlog thresholds. These mirror QueueHealthService::isHealthy()'s
     * own defaults exactly rather than introducing a second, competing
     * set of numbers — that method is the canonical threshold owner and
     * these are read against the same limits it applies.
     */
    public const MAX_PENDING = 500;

    public const MAX_FAILED = 50;

    public const MAX_OLDEST_PENDING_AGE_SECONDS = 900;

    /**
     * Every queue with any observable activity — pending, reserved,
     * delayed, or failed. A queue with no rows anywhere is not
     * reported, because its existence cannot be observed at all from
     * the database driver: queues are created implicitly by pushing
     * to them, so there is no registry of "queues that should exist".
     *
     * @return array<int, QueueObservation>
     */
    public function observeAll(): array
    {
        $jobRows = DB::table('jobs')
            ->select('queue')
            ->selectRaw('count(*) filter (where reserved_at is null and available_at <= ?) as pending', [now()->timestamp])
            ->selectRaw('count(*) filter (where reserved_at is not null) as reserved', [])
            ->selectRaw('count(*) filter (where reserved_at is null and available_at > ?) as delayed', [now()->timestamp])
            ->selectRaw('min(created_at) filter (where reserved_at is null and available_at <= ?) as oldest_pending_created_at', [now()->timestamp])
            ->groupBy('queue')
            ->get()
            ->keyBy('queue');

        $failedRows = DB::table('failed_jobs')
            ->select('queue')
            ->selectRaw('count(*) as failed')
            ->selectRaw('min(failed_at) as oldest_failed_at')
            ->groupBy('queue')
            ->get()
            ->keyBy('queue');

        $queueNames = collect($jobRows->keys())
            ->merge($failedRows->keys())
            ->unique()
            ->sort()
            ->values();

        return $queueNames
            ->map(function (string $queue) use ($jobRows, $failedRows): QueueObservation {
                $jobs = $jobRows->get($queue);
                $failed = $failedRows->get($queue);

                return new QueueObservation(
                    queue: $queue,
                    pending: (int) ($jobs->pending ?? 0),
                    reserved: (int) ($jobs->reserved ?? 0),
                    delayed: (int) ($jobs->delayed ?? 0),
                    failed: (int) ($failed->failed ?? 0),
                    oldestPendingAgeSeconds: $this->ageFromUnixTimestamp($jobs->oldest_pending_created_at ?? null),
                    oldestFailedAgeSeconds: $this->ageFromDateTime($failed->oldest_failed_at ?? null),
                );
            })
            ->all();
    }

    /**
     * Worker liveness evidence. There is none.
     *
     * Returned as a structured, explicit answer rather than a null or
     * a zero so that every caller has to render "Not Monitored"
     * instead of quietly displaying a reassuring 0. If a real worker
     * heartbeat is ever introduced (a Horizon install, an ECS agent
     * writing a heartbeat row), this is the single place that changes.
     *
     * @return array{available: bool, reason: string, expected_workers: null, healthy_workers: null, last_heartbeat_at: null}
     */
    public function workerEvidence(): array
    {
        return [
            'available' => false,
            'reason' => 'No worker heartbeat, process registry, or Horizon installation exists in this platform. '.
                'Queue depth is not evidence of worker liveness — an idle queue and a queue with no running worker '.
                'are indistinguishable from the database queue tables.',
            'expected_workers' => null,
            'healthy_workers' => null,
            'last_heartbeat_at' => null,
        ];
    }

    /**
     * Whether recently-processed-job throughput can be observed. It
     * cannot: the database queue driver deletes a job row on success
     * and keeps no counter, so "processed in the last hour" has no
     * source. Reported explicitly so callers show "Not Available"
     * rather than 0.
     *
     * @return array{available: bool, reason: string, processed_recently: null}
     */
    public function processedRecentlyEvidence(): array
    {
        return [
            'available' => false,
            'reason' => 'The database queue driver deletes each job row on success and keeps no processed counter, '.
                'so recent throughput cannot be measured.',
            'processed_recently' => null,
        ];
    }

    /**
     * Deterministic, threshold-based attention rules over the real
     * observations above. No anomaly detection, no learned baselines —
     * every rule states its source, threshold and observed value so an
     * operator can check the arithmetic.
     *
     * @return array<int, array{signal: string, queue: string, source: string, threshold: string, observed: string, why: string}>
     */
    public function attentionSignals(): array
    {
        $signals = [];

        foreach ($this->observeAll() as $observation) {
            if ($observation->pending > self::MAX_PENDING) {
                $signals[] = [
                    'signal' => 'Queue backlog above threshold',
                    'queue' => $observation->queue,
                    'source' => 'jobs table (pending rows)',
                    'threshold' => self::MAX_PENDING.' pending',
                    'observed' => $observation->pending.' pending',
                    'why' => 'Work is arriving faster than it is being consumed, or nothing is consuming it.',
                ];
            }

            if ($observation->oldestPendingAgeSeconds !== null && $observation->oldestPendingAgeSeconds > self::MAX_OLDEST_PENDING_AGE_SECONDS) {
                $signals[] = [
                    'signal' => 'Oldest pending job exceeds age threshold',
                    'queue' => $observation->queue,
                    'source' => 'jobs.created_at (oldest unreserved, available row)',
                    'threshold' => self::MAX_OLDEST_PENDING_AGE_SECONDS.'s',
                    'observed' => $observation->oldestPendingAgeSeconds.'s',
                    'why' => 'A job has been waiting long enough that no worker is plausibly consuming this queue.',
                ];
            }

            if ($observation->failed > 0) {
                $signals[] = [
                    'signal' => 'Failed jobs present',
                    'queue' => $observation->queue,
                    'source' => 'failed_jobs table',
                    'threshold' => 'more than 0 (critical above '.self::MAX_FAILED.')',
                    'observed' => $observation->failed.' failed',
                    'why' => 'Failed jobs represent work that was accepted and then lost unless retried.',
                ];
            }
        }

        return $signals;
    }

    /**
     * `jobs.created_at` is a raw unix timestamp integer, not a
     * datetime column — read accordingly (same handling as
     * QueueHealthService::oldestPendingJobAgeSeconds()).
     */
    private function ageFromUnixTimestamp(int|string|null $timestamp): ?int
    {
        if ($timestamp === null) {
            return null;
        }

        return max(0, now()->timestamp - (int) $timestamp);
    }

    private function ageFromDateTime(?string $dateTime): ?int
    {
        if ($dateTime === null) {
            return null;
        }

        return max(0, now()->timestamp - strtotime($dateTime));
    }
}
