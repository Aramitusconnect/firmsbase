<?php

namespace Tests\Feature\HealthCheck;

use App\Enums\HealthCheckMonitoringType;
use App\Enums\HealthCheckStatus;
use App\Enums\HealthCheckType;
use App\Services\HealthCheckRegistry;
use App\Services\QueueHealthService;
use App\Services\SchedulerHealthService;
use App\Services\TenantIsolationAnomalyService;
use App\ValueObjects\HealthCheckResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HealthCheckRegistryTest extends TestCase
{
    use RefreshDatabase;

    private HealthCheckRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new HealthCheckRegistry(
            new QueueHealthService,
            new SchedulerHealthService,
            new TenantIsolationAnomalyService,
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

    // --- Storage (real probe) ---------------------------------------------

    public function test_storage_check_is_healthy_on_a_working_disk_and_cleans_up_after_itself(): void
    {
        Storage::fake('local');
        Config::set('filesystems.default', 'local');

        $result = $this->registry->run(HealthCheckType::Storage);

        $this->assertSame(HealthCheckStatus::Healthy, $result->status);
        $this->assertSame(HealthCheckMonitoringType::LiveProbe, $this->registry->monitoringTypeFor(HealthCheckType::Storage));
        // Proves the round-trip really deleted its marker file rather
        // than leaving litter behind.
        Storage::disk('local')->assertDirectoryEmpty('health-checks');
    }

    public function test_storage_check_is_unhealthy_not_a_false_healthy_when_the_disk_cannot_be_written_to(): void
    {
        $unwritableRoot = sys_get_temp_dir().'/health-check-unwritable-'.uniqid();
        mkdir($unwritableRoot, 0500, true);

        // This app's own local/public disks set 'throw' => false (see
        // config/filesystems.php), so a real deployment failure here
        // would surface as a false return value, not an exception —
        // matching that exactly is the point of this test.
        Config::set('filesystems.disks.health_check_unwritable_test_disk', [
            'driver' => 'local',
            'root' => $unwritableRoot,
            'throw' => false,
        ]);
        Config::set('filesystems.default', 'health_check_unwritable_test_disk');

        try {
            $result = $this->registry->run(HealthCheckType::Storage);

            $this->assertSame(HealthCheckStatus::Unhealthy, $result->status);
        } finally {
            chmod($unwritableRoot, 0700);
            @rmdir($unwritableRoot);
        }
    }

    // --- EmailDelivery (real probe) ----------------------------------------

    public function test_email_delivery_check_is_healthy_when_a_real_transport_mailer_resolves_cleanly(): void
    {
        Config::set('mail.mailer', 'smtp');

        $result = $this->registry->run(HealthCheckType::EmailDelivery);

        $this->assertSame(HealthCheckStatus::Healthy, $result->status);
        // Config resolution only, never a live delivery proof — stamped
        // ConfigurationCheck, deliberately not LiveProbe.
        $this->assertSame(HealthCheckMonitoringType::ConfigurationCheck, $this->registry->monitoringTypeFor(HealthCheckType::EmailDelivery));
    }

    public function test_email_delivery_check_is_degraded_not_healthy_when_mailer_is_array(): void
    {
        Config::set('mail.mailer', 'array');

        $result = $this->registry->run(HealthCheckType::EmailDelivery);

        $this->assertSame(HealthCheckStatus::Degraded, $result->status);
    }

    public function test_email_delivery_check_is_degraded_not_healthy_when_mailer_is_log(): void
    {
        Config::set('mail.mailer', 'log');

        $result = $this->registry->run(HealthCheckType::EmailDelivery);

        $this->assertSame(HealthCheckStatus::Degraded, $result->status);
    }

    public function test_email_delivery_check_is_unknown_when_mail_mailer_is_not_configured_at_all(): void
    {
        Config::set('mail.mailer', null);

        $result = $this->registry->run(HealthCheckType::EmailDelivery);

        $this->assertSame(HealthCheckStatus::Unknown, $result->status);
    }

    public function test_email_delivery_check_is_unhealthy_not_healthy_when_ses_mailer_is_selected_without_a_configured_region(): void
    {
        // A missing region is a real failure, not merely "resolves but
        // isn't live" like log/array — the AWS SDK cannot construct an
        // SES client without one at all (verified: Mail::mailer('ses')
        // itself throws in that case), so this must never report
        // Degraded or Healthy.
        Config::set('mail.mailer', 'ses');
        Config::set('services.ses.region', null);

        $result = $this->registry->run(HealthCheckType::EmailDelivery);

        $this->assertSame(HealthCheckStatus::Unhealthy, $result->status);
    }

    public function test_email_delivery_check_is_healthy_when_ses_mailer_has_a_configured_region_even_without_static_keys(): void
    {
        Config::set('mail.mailer', 'ses');
        Config::set('services.ses.region', 'us-east-1');
        // Deliberately null — this codebase's ECS-task-role deployments
        // legitimately omit static key/secret in favor of the AWS SDK's
        // default credential provider chain (see config/services.php's
        // own comment on the 'ses' block); this must not be treated as
        // a failure on its own.
        Config::set('services.ses.key', null);
        Config::set('services.ses.secret', null);

        $result = $this->registry->run(HealthCheckType::EmailDelivery);

        $this->assertSame(HealthCheckStatus::Healthy, $result->status);
    }

    // --- DocumentScanning (real probe) --------------------------------------

    public function test_document_scanning_check_is_unknown_not_healthy_when_no_clamav_socket_is_configured(): void
    {
        Config::set('services.clamav.socket', null);

        $result = $this->registry->run(HealthCheckType::DocumentScanning);

        $this->assertSame(HealthCheckStatus::Unknown, $result->status);
        $this->assertSame(HealthCheckMonitoringType::LiveProbe, $this->registry->monitoringTypeFor(HealthCheckType::DocumentScanning));
    }

    public function test_document_scanning_check_is_unhealthy_not_healthy_when_a_socket_is_configured_but_unreachable(): void
    {
        // A socket path that genuinely does not exist — proves this
        // check does not fabricate Healthy just because a socket
        // string is present in config.
        $socketPath = sys_get_temp_dir().'/health-check-clamav-nobody-home-'.uniqid().'.sock';
        Config::set('services.clamav.socket', 'unix://'.$socketPath);
        Config::set('services.clamav.timeout_seconds', 1.0);

        $result = $this->registry->run(HealthCheckType::DocumentScanning);

        $this->assertSame(HealthCheckStatus::Unhealthy, $result->status);
    }

    // --- WebUptime / PaymentWebhooks remain genuine stubs -------------------

    public function test_web_uptime_and_payment_webhooks_remain_not_monitored(): void
    {
        $this->assertSame(HealthCheckMonitoringType::NotMonitored, $this->registry->monitoringTypeFor(HealthCheckType::WebUptime));
        $this->assertSame(HealthCheckMonitoringType::NotMonitored, $this->registry->monitoringTypeFor(HealthCheckType::PaymentWebhooks));
    }
}
