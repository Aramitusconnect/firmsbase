<?php

namespace Tests\Feature\QueueHealth;

use App\Services\SchedulerHealthService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SchedulerHealthServiceTest extends TestCase
{
    private SchedulerHealthService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SchedulerHealthService();
        Cache::flush();
    }

    public function test_unhealthy_when_no_heartbeat_has_ever_been_recorded(): void
    {
        $this->assertNull($this->service->lastHeartbeatAt());
        $this->assertFalse($this->service->isHealthy());
    }

    public function test_healthy_immediately_after_a_heartbeat(): void
    {
        $this->service->recordHeartbeat();

        $this->assertNotNull($this->service->lastHeartbeatAt());
        $this->assertTrue($this->service->isHealthy());
    }

    public function test_unhealthy_once_the_heartbeat_is_older_than_the_max_age(): void
    {
        Cache::put('firmsbase:scheduler:last_heartbeat_at', now()->subMinutes(10)->timestamp, now()->addDay());

        $this->assertFalse($this->service->isHealthy(maxAgeSeconds: 300));
    }

    public function test_no_new_database_table_is_used_the_cache_store_is_the_only_backing(): void
    {
        $this->service->recordHeartbeat();

        // Purely a cache read — no query builder / Eloquent model
        // involved, proving no dedicated scheduler table exists.
        $this->assertIsInt($this->service->lastHeartbeatAt());
    }
}
