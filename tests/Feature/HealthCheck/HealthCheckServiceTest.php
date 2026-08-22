<?php

namespace Tests\Feature\HealthCheck;

use App\Enums\HealthCheckType;
use App\Models\Firm;
use App\Models\HealthCheck;
use App\Services\HealthCheckRegistry;
use App\Services\HealthCheckService;
use App\Services\QueueHealthService;
use App\Services\SchedulerHealthService;
use App\Services\TenantContextService;
use App\Services\TenantIsolationAnomalyService;
use App\Services\VirusScan\ClamAvVirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class HealthCheckServiceTest extends TestCase
{
    use RefreshDatabase;

    private HealthCheckService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new HealthCheckService(new HealthCheckRegistry(
            new QueueHealthService,
            new SchedulerHealthService,
            new TenantIsolationAnomalyService,
        ));
    }

    public function test_run_all_and_record_persists_nine_append_only_rows(): void
    {
        $checks = $this->service->runAllAndRecord();

        $this->assertCount(9, $checks);
        $this->assertSame(9, HealthCheck::query()->count());
    }

    public function test_tenant_isolation_anomalies_check_is_recorded_with_the_given_firm_id_others_are_platform_wide(): void
    {
        $firm = Firm::factory()->create();

        $this->service->runAllAndRecord($firm);

        $tenantCheck = app(TenantContextService::class)->runWithFirmContext(
            $firm,
            fn () => HealthCheck::query()->where('check_type', HealthCheckType::TenantIsolationAnomalies->value)->first()
        );
        $webUptimeCheck = HealthCheck::query()->where('check_type', HealthCheckType::WebUptime->value)->first();

        $this->assertSame($firm->id, $tenantCheck->firm_id);
        $this->assertNull($webUptimeCheck->firm_id);
    }

    public function test_latest_for_returns_the_most_recent_result_of_that_type(): void
    {
        $this->service->runAllAndRecord();
        $this->service->runAllAndRecord();

        $latest = $this->service->latestFor(HealthCheckType::WebUptime);

        $this->assertSame(2, HealthCheck::query()->where('check_type', HealthCheckType::WebUptime->value)->count());
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

        // DocumentScanning honestly reports Unknown whenever no clamd
        // socket is configured (see ClamAvVirusScanner's own docblock:
        // "every CI runner, every other engineer's local machine ... has
        // no clamd sidecar deployed yet") — that's by design, not a bug.
        // Proving "every check reports healthy or degraded" therefore
        // requires simulating an environment where clamd IS available,
        // via a container-level mock, rather than depending on a real
        // daemon this test would otherwise never portably have.
        config(['services.clamav.socket' => 'unix:///tmp/fake-clamd-for-test.sock']);
        $scanner = Mockery::mock(ClamAvVirusScanner::class);
        $scanner->shouldReceive('ping')->andReturn(true);
        $this->app->instance(ClamAvVirusScanner::class, $scanner);

        $this->service->runAllAndRecord();

        $this->assertTrue($this->service->isOverallHealthy());
    }
}
