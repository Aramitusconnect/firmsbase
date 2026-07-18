<?php

namespace Tests\Feature\Deployment\Health;

use App\Enums\DeploymentHealthReportMode;
use App\Enums\DeploymentMode;
use App\Enums\HealthCheckStatus;
use App\Models\PrivateEnterpriseSettings;
use App\Services\DeploymentHealthEnvelopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Deployment\Concerns\SetsUpDeploymentFirm;
use Tests\TestCase;

class DeploymentHealthEnvelopeServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpDeploymentFirm;

    public function test_envelope_records_anonymized_heartbeat_version_and_migration_status(): void
    {
        $firm = $this->makeDeploymentFirm(DeploymentMode::Dedicated);

        $envelope = app(DeploymentHealthEnvelopeService::class)->buildEnvelope($firm, '2026.7.0', '2026.7.0', 'completed');

        $this->assertNotNull($envelope->heartbeatAt);
        $this->assertSame('2026.7.0', $envelope->version);
        $this->assertSame('completed', $envelope->migrationStatus);
        $this->assertSame(HealthCheckStatus::Healthy, $envelope->status);

        // deployment_health_checks now carries permanent FORCE ROW
        // LEVEL SECURITY, so this bare assertDatabaseHas() (outside any
        // context) must be explicitly wrapped, or it would incorrectly
        // see zero rows rather than the row it means to confirm exists.
        $this->runWithFirmContext($firm, fn () => $this->assertDatabaseHas('deployment_health_checks', ['firm_id' => $firm->id, 'version' => '2026.7.0']));
    }

    public function test_envelope_carries_no_pii_or_firm_identifying_content_beyond_the_id(): void
    {
        $firm = $this->makeDeploymentFirm(DeploymentMode::Dedicated, \App\Enums\CustomerType::LawFirm);

        app(DeploymentHealthEnvelopeService::class)->buildEnvelope($firm, '2026.7.0', '2026.7.0');

        // deployment_health_checks now carries permanent FORCE ROW
        // LEVEL SECURITY, so this bare read (outside any context) must
        // be explicitly wrapped, or it would incorrectly find no row.
        $row = $this->runWithFirmContext(
            $firm,
            fn () => \App\Models\DeploymentHealthCheck::query()->where('firm_id', $firm->id)->firstOrFail(),
        );

        $this->assertStringNotContainsString($firm->name, (string) $row->detail);
    }

    public function test_telemetry_prohibited_forces_offline_report_mode(): void
    {
        $firm = $this->makeDeploymentFirm(DeploymentMode::PrivateEnterprise);

        // private_enterprise_settings now carries permanent FORCE ROW
        // LEVEL SECURITY, so the bare create() has no ambient tenant
        // context and is wrapped narrowly, same established precedent
        // as DeploymentConfigTest's own deployment_configs wrap.
        $this->runWithFirmContext($firm, fn () => PrivateEnterpriseSettings::factory()->forFirm($firm)->telemetryProhibited()->create());

        $envelope = app(DeploymentHealthEnvelopeService::class)->buildEnvelope($firm->fresh(), '2026.7.0', '2026.7.0');

        $this->assertSame(DeploymentHealthReportMode::OfflineReport, $envelope->reportedVia);

        // deployment_health_checks now carries permanent FORCE ROW
        // LEVEL SECURITY, so this bare assertDatabaseHas() (outside any
        // context) must be explicitly wrapped, or it would incorrectly
        // see zero rows rather than the row it means to confirm exists.
        $this->runWithFirmContext($firm, fn () => $this->assertDatabaseHas('deployment_health_checks', ['firm_id' => $firm->id, 'reported_via' => DeploymentHealthReportMode::OfflineReport->value]));
    }

    public function test_reported_via_defaults_to_live_when_telemetry_is_not_prohibited(): void
    {
        $firm = $this->makeDeploymentFirm(DeploymentMode::Dedicated);

        $envelope = app(DeploymentHealthEnvelopeService::class)->buildEnvelope($firm, '2026.7.0', '2026.7.0');

        $this->assertSame(DeploymentHealthReportMode::Live, $envelope->reportedVia);
    }

    public function test_reportOffline_never_makes_a_network_call_and_writes_locally(): void
    {
        $firm = $this->makeDeploymentFirm(DeploymentMode::PrivateEnterprise);

        $check = app(DeploymentHealthEnvelopeService::class)->reportOffline($firm, '2026.7.0', 'completed');

        $this->assertSame(DeploymentHealthReportMode::OfflineReport, $check->reported_via);

        $source = file_get_contents(app_path('Services/DeploymentHealthEnvelopeService.php'));
        foreach (['Http::', 'curl_init', 'fsockopen', 'GuzzleHttp'] as $needle) {
            $this->assertStringNotContainsString($needle, $source);
        }
    }

    public function test_version_skew_beyond_policy_is_reflected_as_degraded_status(): void
    {
        $firm = $this->makeDeploymentFirm(DeploymentMode::Dedicated);

        $envelope = app(DeploymentHealthEnvelopeService::class)->buildEnvelope($firm, '2026.4.0', '2026.7.0');

        $this->assertSame(HealthCheckStatus::Degraded, $envelope->status);
    }
}
