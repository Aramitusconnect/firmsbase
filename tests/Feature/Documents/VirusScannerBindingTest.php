<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Services\VirusScan\ClamAvVirusScanner;
use App\Services\VirusScan\FakeVirusScanner;
use App\Services\VirusScan\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Non-payment completion program, finding DOC-001 — VirusScanner::class
 * was unconditionally bound to FakeVirusScanner in AppServiceProvider,
 * with no environment gate at all, unlike the sibling StripeGateway
 * binding a few lines above it (config-gated via
 * PaymentGatewaySimulationPolicyService::isSimulationEnabled()). This
 * proves the corrected binding: `services.clamav.socket`
 * (CLAMAV_SOCKET, unset by default in every environment) is now the
 * single source of truth — with no socket configured the container
 * still resolves FakeVirusScanner (behavior unchanged from before this
 * fix), and once a socket is configured it resolves the real,
 * already-tested ClamAvVirusScanner (see
 * ClamAvVirusScannerLocalProofTest) instead of silently continuing to
 * fake scan results forever. Neither case requires a live clamd daemon
 * — the socket value here is faked purely via config(), never dialed.
 */
class VirusScannerBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_with_no_clamav_socket_configured_the_container_resolves_fakevirusscanner(): void
    {
        config(['services.clamav.socket' => null]);

        $this->assertInstanceOf(FakeVirusScanner::class, app(VirusScanner::class));
    }

    public function test_with_an_empty_clamav_socket_configured_the_container_resolves_fakevirusscanner(): void
    {
        config(['services.clamav.socket' => '']);

        $this->assertInstanceOf(FakeVirusScanner::class, app(VirusScanner::class));
    }

    public function test_with_a_clamav_socket_configured_the_container_resolves_clamavvirusscanner(): void
    {
        config(['services.clamav.socket' => 'unix:///var/run/clamav/clamd.ctl']);

        $this->assertInstanceOf(ClamAvVirusScanner::class, app(VirusScanner::class));
    }
}
