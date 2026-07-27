<?php

namespace Tests\Feature\QueueHealth;

use App\Services\QueueHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * QueueHealthServiceRetryDeleteTest — Phase 4 (FirmsVault Platform
 * Admin Control Center, "Operations"). Proves retryFailedJob()/
 * deleteFailedJob()/exceptionSummary()/pendingJobsCountByQueue()
 * behave exactly like Laravel's own `queue:retry`/`queue:forget`
 * commands (same underlying `queue.failer`/`queue` container
 * bindings), and are TOCTOU-safe (return false rather than throwing
 * when the target row no longer exists).
 */
class QueueHealthServiceRetryDeleteTest extends TestCase
{
    use RefreshDatabase;

    private QueueHealthService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new QueueHealthService;
    }

    private function insertFailedJob(array $overrides = []): string
    {
        $uuid = (string) Str::uuid();

        DB::table('failed_jobs')->insert(array_merge([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['uuid' => $uuid, 'attempts' => 3, 'data' => ['commandName' => 'stdClass']]),
            'exception' => "RuntimeException: something broke\n#0 /app/Foo.php(10): bar()\n#1 {main}",
            'failed_at' => now(),
        ], $overrides));

        return $uuid;
    }

    public function test_delete_failed_job_removes_the_row(): void
    {
        $uuid = $this->insertFailedJob();

        $this->assertTrue($this->service->deleteFailedJob($uuid));
        $this->assertSame(0, DB::table('failed_jobs')->where('uuid', $uuid)->count());
    }

    public function test_delete_failed_job_returns_false_when_the_row_does_not_exist(): void
    {
        $this->assertFalse($this->service->deleteFailedJob((string) Str::uuid()));
    }

    public function test_retry_failed_job_pushes_back_onto_the_queue_and_removes_the_failed_row(): void
    {
        $uuid = $this->insertFailedJob(['queue' => 'default']);

        $this->assertTrue($this->service->retryFailedJob($uuid));
        $this->assertSame(0, DB::table('failed_jobs')->where('uuid', $uuid)->count());
        $this->assertSame(1, DB::table('jobs')->where('queue', 'default')->count());

        $pushed = DB::table('jobs')->where('queue', 'default')->first();
        $payload = json_decode($pushed->payload, true);
        $this->assertSame(0, $payload['attempts'], 'Attempts must be reset to 0, mirroring RetryCommand::resetAttempts().');
    }

    public function test_retry_failed_job_returns_false_when_the_row_does_not_exist(): void
    {
        $this->assertFalse($this->service->retryFailedJob((string) Str::uuid()));
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_exception_summary_is_a_single_truncated_line_never_the_full_trace(): void
    {
        $summary = $this->service->exceptionSummary("RuntimeException: boom\n#0 /app/Foo.php(10): bar()\n#1 {main}");

        $this->assertSame('RuntimeException: boom', $summary);
        $this->assertStringNotContainsString('#0', $summary);
        $this->assertStringNotContainsString('#1', $summary);
    }

    public function test_exception_summary_handles_null_and_empty(): void
    {
        $this->assertSame('—', $this->service->exceptionSummary(null));
        $this->assertSame('—', $this->service->exceptionSummary(''));
    }

    public function test_pending_jobs_count_by_queue_breaks_down_per_queue(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);
        DB::table('jobs')->insert([
            'queue' => 'high',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);
        DB::table('jobs')->insert([
            'queue' => 'high',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $byQueue = $this->service->pendingJobsCountByQueue();

        $this->assertSame(['default' => 1, 'high' => 2], $byQueue);
    }
}
