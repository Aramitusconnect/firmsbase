<?php

namespace Tests\Feature\Webhooks\Firewall;

use Tests\TestCase;

/**
 * Correction #4: real HTTP delivery is NOT included in Phase 14. This
 * sweeps every app/ source file introduced or touched by Phase 14 (not
 * just WebhookDestinationValidationService) and asserts none of them
 * contain any real outbound-transport primitive. Mirrors the pattern
 * established by Phase 12/13's forbidden-integrations firewall tests.
 */
class WebhookNoRealHttpAnywhereTest extends TestCase
{
    private const FORBIDDEN_TOKENS = [
        'Http::',
        'GuzzleHttp',
        'curl_init',
        'curl_exec',
        'fsockopen',
        "file_get_contents('http",
        'file_get_contents("http',
        'stream_socket_client',
        'proc_open',
        'pfsockopen',
        'gethostbyname',
        'dns_get_record',
        'checkdnsrr',
    ];

    private const APP_ROOTS = [
        'app/Enums',
        'app/ValueObjects',
        'app/Models',
        'app/Services',
        'app/Jobs',
        'database/migrations',
        'database/factories',
    ];

    public function test_no_phase_14_app_file_references_any_real_transport_or_dns_primitive(): void
    {
        $violations = [];

        foreach (self::APP_ROOTS as $root) {
            $fullRoot = base_path($root);

            if (! is_dir($fullRoot)) {
                continue;
            }

            $files = $this->phpFilesUnder($fullRoot);

            foreach ($files as $file) {
                if (! $this->isPhase14File($file)) {
                    continue;
                }

                $source = file_get_contents($file);

                foreach (self::FORBIDDEN_TOKENS as $token) {
                    if (str_contains($source, $token)) {
                        $violations[] = "{$file} contains forbidden token: {$token}";
                    }
                }
            }
        }

        $this->assertEmpty($violations, "Real HTTP/DNS primitives found:\n" . implode("\n", $violations));
    }

    public function test_fake_webhook_transport_is_the_only_transport_implementation(): void
    {
        $servicesDir = base_path('app/Services');
        $files = $this->phpFilesUnder($servicesDir);

        $implementations = [];

        foreach ($files as $file) {
            $source = file_get_contents($file);

            if (str_contains($source, 'implements WebhookTransportInterface')) {
                $implementations[] = basename($file);
            }
        }

        $this->assertSame(['FakeWebhookTransport.php'], $implementations);
    }

    public function test_webhook_dispatch_job_type_hints_the_concrete_fake_transport_not_the_interface(): void
    {
        $source = file_get_contents(base_path('app/Jobs/WebhookDispatchJob.php'));

        $this->assertStringContainsString('FakeWebhookTransport', $source);

        // Confirms handle()'s signature resolves the concrete class,
        // never binding WebhookTransportInterface directly, per
        // correction #4 (no AppServiceProvider/config modification).
        $this->assertMatchesRegularExpression(
            '/function handle\([^)]*FakeWebhookTransport[^)]*\)/',
            $source
        );
    }

    /**
     * @return string[]
     */
    private function phpFilesUnder(string $dir): array
    {
        $result = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->getExtension() === 'php') {
                $result[] = $fileInfo->getPathname();
            }
        }

        return $result;
    }

    private function isPhase14File(string $path): bool
    {
        return str_contains($path, 'Webhook') || str_contains(basename($path), 'Firm.php');
    }
}
