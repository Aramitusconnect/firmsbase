<?php

namespace Tests\Feature\HealthCheck;

use App\Enums\HealthCheckStatus;
use App\Enums\HealthCheckType;
use App\Services\HealthCheckRegistry;
use App\Services\QueueHealthService;
use App\Services\SchedulerHealthService;
use App\Services\TenantIsolationAnomalyService;
use App\ValueObjects\HealthCheckResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthCheckRegistryTest extends TestCase
{
    use RefreshDatabase;

    private HealthCheckRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new HealthCheckRegistry(
            new QueueHealthService(),
            new SchedulerHealthService(),
            new TenantIsolationAnomalyService(),
        );
    }

    public function test_all_nine_health_check_types_are_registered_by_default(): void
    {
        foreach (HealthCheckType::cases() as $type) {
            $this->assertTrue($this->registry->isRegistered($type), "{$type->value} should be registered by default");
        }
    }

    public function test_run_all_returns_nine_results(): void
    {
        $results = $this->registry->runAll();

        $this->assertCount(9, $results);
    }

    public function test_queue_workers_check_reuses_phase_4_queue_health_service(): void
    {
        $result = $this->registry->run(HealthCheckType::QueueWorkers);

        $this->assertInstanceOf(HealthCheckResult::class, $result);
        $this->assertSame(HealthCheckStatus::Healthy, $result->status);
    }

    public function test_scheduler_check_reuses_phase_4_scheduler_health_service(): void
    {
        $result = $this->registry->run(HealthCheckType::Scheduler);

        // No heartbeat recorded yet in this test, so Phase 4's
        // SchedulerHealthService::isHealthy() must report false —
        // proving this really delegates rather than always saying healthy.
        $this->assertSame(HealthCheckStatus::Unhealthy, $result->status);
    }

    public function test_a_new_check_can_be_registered_and_overridden_for_tests_without_a_real_provider(): void
    {
        $this->registry->register(HealthCheckType::WebUptime, fn () => new HealthCheckResult(HealthCheckType::WebUptime, HealthCheckStatus::Degraded, 'simulated outage'));

        $result = $this->registry->run(HealthCheckType::WebUptime);

        $this->assertSame(HealthCheckStatus::Degraded, $result->status);
    }
}
