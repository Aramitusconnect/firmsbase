<?php

namespace Tests\Feature\Operations;

use App\Services\QueueHealthService;
use App\Services\QueueObservabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Operations Control Plane — per-queue observability, and the refusal
 * to infer worker health from queue depth.
 *
 * The central assertion in this file is a negative one: no matter
 * what the queue looks like, this platform never claims to know
 * whether a worker is alive, because it has no way to know.
 */
class QueueObservabilityTest extends TestCase
{
    use RefreshDatabase;

    private function service(): QueueObservabilityService
    {
        return app(QueueObservabilityService::class);
    }

    private function pushJob(string $queue, int $createdSecondsAgo = 0, ?int $reservedAt = null, int $availableInSeconds = 0): void
    {
        DB::table('jobs')->insert([
            'queue' => $queue,
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ExampleJob', 'attempts' => 0]),
            'attempts' => 0,
            'reserved_at' => $reservedAt,
            'available_at' => now()->timestamp + $availableInSeconds,
            'created_at' => now()->timestamp - $createdSecondsAgo,
        ]);
    }

    private function failJob(string $queue, int $failedSecondsAgo = 0): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => $queue,
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ExampleJob']),
            'exception' => "RuntimeException: something broke\n#0 /app/frame.php",
            'failed_at' => now()->subSeconds($failedSecondsAgo),
        ]);
    }

    public function test_pending_reserved_and_delayed_are_counted_separately(): void
    {
        $this->pushJob('default');
        $this->pushJob('default');
        $this->pushJob('default', reservedAt: now()->timestamp);
        $this->pushJob('default', availableInSeconds: 3600);

        $observations = $this->service()->observeAll();

        $this->assertCount(1, $observations);
        $default = $observations[0];

        $this->assertSame('default', $default->queue);
        $this->assertSame(2, $default->pending, 'only unreserved, currently-available jobs are pending');
        $this->assertSame(1, $default->reserved, 'a reserved job is in flight, not pending');
        $this->assertSame(1, $default->delayed, 'a future-dated job is delayed, not pending');
        $this->assertSame(4, $default->total());
    }

    public function test_each_queue_is_reported_independently(): void
    {
        $this->pushJob('default');
        $this->pushJob('critical');
        $this->pushJob('critical');
        $this->failJob('critical');

        $byQueue = collect($this->service()->observeAll())->keyBy->queue;

        $this->assertSame(1, $byQueue['default']->pending);
        $this->assertSame(0, $byQueue['default']->failed);
        $this->assertSame(2, $byQueue['critical']->pending);
        $this->assertSame(1, $byQueue['critical']->failed);
    }

    public function test_a_queue_with_only_failures_is_still_reported(): void
    {
        $this->failJob('reports');

        $byQueue = collect($this->service()->observeAll())->keyBy->queue;

        $this->assertArrayHasKey('reports', $byQueue->all());
        $this->assertSame(0, $byQueue['reports']->pending);
        $this->assertSame(1, $byQueue['reports']->failed);
    }

    public function test_oldest_pending_and_oldest_failed_ages_are_measured(): void
    {
        $this->pushJob('default', createdSecondsAgo: 120);
        $this->pushJob('default', createdSecondsAgo: 30);
        $this->failJob('default', failedSecondsAgo: 600);

        $observation = $this->service()->observeAll()[0];

        $this->assertGreaterThanOrEqual(119, $observation->oldestPendingAgeSeconds);
        $this->assertLessThanOrEqual(125, $observation->oldestPendingAgeSeconds);
        $this->assertGreaterThanOrEqual(598, $observation->oldestFailedAgeSeconds);
    }

    public function test_ages_are_null_rather_than_zero_when_nothing_is_waiting(): void
    {
        $this->failJob('default');

        $observation = $this->service()->observeAll()[0];

        $this->assertNull(
            $observation->oldestPendingAgeSeconds,
            'no pending job means no age, which is not the same as an age of zero',
        );
    }

    public function test_reserved_jobs_do_not_count_toward_the_oldest_pending_age(): void
    {
        $this->pushJob('default', createdSecondsAgo: 9999, reservedAt: now()->timestamp);
        $this->pushJob('default', createdSecondsAgo: 10);

        $observation = $this->service()->observeAll()[0];

        $this->assertLessThan(
            100,
            $observation->oldestPendingAgeSeconds,
            'an in-flight job is being worked on and must not inflate the pending-age signal',
        );
    }

    // --- The core refusal ---

    public function test_worker_health_is_never_inferred_from_an_empty_queue(): void
    {
        $evidence = $this->service()->workerEvidence();

        $this->assertFalse($evidence['available']);
        $this->assertNull($evidence['expected_workers'], 'an unknown expectation must be null, never 0');
        $this->assertNull($evidence['healthy_workers'], 'an unknown healthy count must be null, never 0');
        $this->assertNull($evidence['last_heartbeat_at']);
        $this->assertStringContainsString('Queue depth is not evidence of worker liveness', $evidence['reason']);
    }

    public function test_worker_evidence_is_unchanged_by_a_large_backlog(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->pushJob('default');
        }

        $this->assertFalse(
            $this->service()->workerEvidence()['available'],
            'a backlog is not evidence about workers either — in either direction',
        );
    }

    public function test_processed_recently_is_reported_as_unavailable_not_zero(): void
    {
        $evidence = $this->service()->processedRecentlyEvidence();

        $this->assertFalse($evidence['available']);
        $this->assertNull($evidence['processed_recently']);
    }

    // --- Deterministic attention rules ---

    public function test_no_attention_signals_on_a_quiet_healthy_queue(): void
    {
        $this->pushJob('default');

        $this->assertSame([], $this->service()->attentionSignals());
    }

    public function test_a_failed_job_raises_a_signal_that_states_its_own_evidence(): void
    {
        $this->failJob('default');

        $signals = $this->service()->attentionSignals();

        $this->assertCount(1, $signals);
        $this->assertSame('Failed jobs present', $signals[0]['signal']);
        $this->assertSame('default', $signals[0]['queue']);
        $this->assertSame('failed_jobs table', $signals[0]['source']);
        $this->assertSame('1 failed', $signals[0]['observed']);
        $this->assertNotEmpty($signals[0]['why']);
    }

    public function test_an_old_pending_job_raises_an_age_signal(): void
    {
        $this->pushJob('default', createdSecondsAgo: QueueObservabilityService::MAX_OLDEST_PENDING_AGE_SECONDS + 60);

        $signals = collect($this->service()->attentionSignals());

        $this->assertTrue($signals->contains(
            fn (array $signal): bool => $signal['signal'] === 'Oldest pending job exceeds age threshold'
        ));
    }

    public function test_thresholds_match_the_canonical_queue_health_service_defaults(): void
    {
        // Guards against a second, competing set of threshold numbers
        // drifting away from QueueHealthService::isHealthy()'s.
        $reflection = new \ReflectionMethod(QueueHealthService::class, 'isHealthy');
        $defaults = collect($reflection->getParameters())
            ->mapWithKeys(fn (\ReflectionParameter $p): array => [$p->getName() => $p->isDefaultValueAvailable() ? $p->getDefaultValue() : null]);

        $this->assertSame($defaults['maxPendingCount'], QueueObservabilityService::MAX_PENDING);
        $this->assertSame($defaults['maxFailedCount'], QueueObservabilityService::MAX_FAILED);
        $this->assertSame($defaults['maxOldestPendingAgeSeconds'], QueueObservabilityService::MAX_OLDEST_PENDING_AGE_SECONDS);
    }
}
