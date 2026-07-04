<?php

namespace Tests\Feature\HealthCheck;

use App\Enums\HealthCheckStatus;
use App\Models\Firm;
use App\Services\TenantIsolationAnomalyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationAnomalyServiceTest extends TestCase
{
    use RefreshDatabase;

    private TenantIsolationAnomalyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TenantIsolationAnomalyService();
    }

    public function test_healthy_when_no_anomaly_has_ever_been_recorded(): void
    {
        $result = $this->service->checkForKnownAnomalyPatterns();

        $this->assertSame(HealthCheckStatus::Healthy, $result->status);
    }

    public function test_unhealthy_immediately_after_an_anomaly_is_recorded(): void
    {
        $firm = Firm::factory()->create();

        $this->service->recordAnomaly($firm, 'Query returned rows from a different firm_id');

        $result = $this->service->checkForKnownAnomalyPatterns();

        $this->assertSame(HealthCheckStatus::Unhealthy, $result->status);
        $this->assertStringContainsString('different firm_id', $result->detail);
    }

    public function test_healthy_again_once_the_anomaly_falls_outside_the_lookback_window(): void
    {
        $firm = Firm::factory()->create();
        $anomaly = $this->service->recordAnomaly($firm, 'Old anomaly');
        $anomaly->update(['checked_at' => now()->subHours(3)]);

        $result = $this->service->checkForKnownAnomalyPatterns(lookbackMinutes: 60);

        $this->assertSame(HealthCheckStatus::Healthy, $result->status);
    }

    public function test_record_anomaly_can_be_platform_wide(): void
    {
        $anomaly = $this->service->recordAnomaly(null, 'Suspicious platform-wide query pattern');

        $this->assertNull($anomaly->firm_id);
    }
}
