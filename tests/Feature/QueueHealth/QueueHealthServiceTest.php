<?php

namespace Tests\Feature\QueueHealth;

use App\Services\QueueHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QueueHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    private QueueHealthService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new QueueHealthService();
    }

    public function test_healthy_with_empty_queue_and_no_failed_jobs(): void
    {
        $this->assertSame(0, $this->service->pendingJobsCount());
        $this->assertSame(0, $this->service->failedJobsCount());
        $this->assertTrue($this->service->isHealthy());
    }

    public function test_oldest_pending_job_age_is_null_when_queue_is_empty(): void
    {
        $this->assertNull($this->service->oldestPendingJobAgeSeconds());
    }

    public function test_becomes_unhealthy_when_failed_jobs_exceed_the_threshold(): void
    {
        for ($i = 0; $i < 3; $i++) {
            DB::table('failed_jobs')->insert([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'connection' => 'database',
                'queue' => 'default',
                'payload' => '{}',
                'exception' => 'test exception',
                'failed_at' => now(),
            ]);
        }

        $this->assertSame(3, $this->service->failedJobsCount());
        $this->assertFalse($this->service->isHealthy(maxFailedCount: 2));
        $this->assertTrue($this->service->isHealthy(maxFailedCount: 5));
    }

    public function test_becomes_unhealthy_when_the_oldest_pending_job_is_too_old(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subHour()->timestamp,
            'created_at' => now()->subHour()->timestamp,
        ]);

        $age = $this->service->oldestPendingJobAgeSeconds();

        $this->assertGreaterThanOrEqual(3500, $age);
        $this->assertFalse($this->service->isHealthy(maxOldestPendingAgeSeconds: 60));
    }
}
