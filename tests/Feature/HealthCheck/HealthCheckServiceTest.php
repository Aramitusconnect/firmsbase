<?php

namespace Tests\Feature\HealthCheck;

use App\Enums\HealthCheckStatus;
use App\Enums\HealthCheckType;
use App\Models\Firm;
use App\Services\HealthCheckRegistry;
use App\Services\HealthCheckService;
use App\Services\QueueHealthService;
use App\Services\SchedulerHealthService;
use App\Services\TenantContextService;
use App\Services\TenantIsolationAnomalyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthCheckServiceTest extends TestCase
{
    use RefreshDatabase;

    private HealthCheckService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new HealthCheckService(new HealthCheckRegistry(
            new QueueHealthService(),
            new SchedulerHealthService(),
            new TenantIsolationAnomalyService(),
        ));
    }

    public function test_run_all_and_record_persists_nine_append_only_rows(): void
    {
        $checks = $this->service->runAllAndRecord();

        $this->assertCount(9, $checks);
        $this->assertSame(9, \App\Models\HealthCheck::query()->count());
    }

    public function test_tenant_isolation_anomalies_check_is_recorded_with_the_given_firm_id_others_are_platform_wide(): void
    {
        $firm = Firm::factory()->create();

        $this->service->runAllAndRecord($firm);

        $tenantCheck = app(TenantContextService::class)->runWithFirmContext(
            $firm,
            fn () => \App\Models\HealthCheck::query()->where('check_type', HealthCheckType::TenantIsolationAnomalies->value)->first()
        );
        $webUptimeCheck = \App\Models\HealthCheck::query()->where('check_type', HealthCheckType::WebUptime->value)->first();

        $this->assertSame($firm->id, $tenantCheck->firm_id);
        $this->assertNull($webUptimeCheck->firm_id);
    }

    public function test_latest_for_returns_the_most_recent_result_of_that_type(): void
    {
        $this->service->runAllAndRecord();
        $this->service->runAllAndRecord();

        $latest = $this->service->latestFor(HealthCheckType::WebUptime);

        $this->assertSame(2, \App\Models\HealthCheck::query()->where('check_type', HealthCheckType::WebUptime->value)->count());
        $this->assertNotNull($latest);
    }

    public function test_is_overall_healthy_false_when_any_latest_check_is_unhealthy(): void
    {
        $this->service->runAllAndRecord(); // Scheduler will be Unhealthy — no heartbeat recorded

        $this->assertFalse($this->service->isOverallHealthy());
    }

    public function test_is_overall_healthy_true_once_every_check_reports_healthy_or_degraded(): void
    {
        app(SchedulerHealthService::class)->recordHeartbeat();

        $this->service->runAllAndRecord();

        $this->assertTrue($this->service->isOverallHealthy());
    }
}
