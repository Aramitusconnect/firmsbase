<?php

namespace App\ValueObjects;

/**
 * QueueObservation — the real, measured state of ONE queue, read from
 * Laravel's own database queue tables. Operations Control Plane
 * addition.
 *
 * Every count here is a genuine SELECT against `jobs`/`failed_jobs`.
 * The two things this object deliberately CANNOT report are
 * $processedRecently and worker liveness: the database queue driver
 * keeps no processed-jobs counter and no worker registry, so both are
 * absent rather than guessed. See QueueObservabilityService.
 */
final readonly class QueueObservation
{
    public function __construct(
        public string $queue,
        public int $pending,
        public int $reserved,
        public int $delayed,
        public int $failed,
        public ?int $oldestPendingAgeSeconds,
        public ?int $oldestFailedAgeSeconds,
    ) {}

    public function total(): int
    {
        return $this->pending + $this->reserved + $this->delayed;
    }

    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }
}
