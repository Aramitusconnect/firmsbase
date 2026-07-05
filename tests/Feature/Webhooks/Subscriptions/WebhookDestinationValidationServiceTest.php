<?php

namespace Tests\Feature\Webhooks\Subscriptions;

use App\Services\WebhookDestinationValidationService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Correction #5: pure validation, no network/DNS calls, rejecting an
 * explicit list of unsafe schemes/hosts/ranges.
 */
class WebhookDestinationValidationServiceTest extends TestCase
{
    private WebhookDestinationValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WebhookDestinationValidationService();
    }

    public function test_a_normal_https_url_is_accepted(): void
    {
        $this->service->assertSafe('https://example.com/webhooks');
        $this->assertTrue(true);
    }

    #[DataProvider('unsafeUrls')]
    public function test_unsafe_destination_is_rejected(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->assertSafe($url);
    }

    public static function unsafeUrls(): array
    {
        return [
            'ftp scheme' => ['ftp://example.com/hooks'],
            'file scheme' => ['file:///etc/passwd'],
            'localhost' => ['https://localhost/hooks'],
            'loopback 127.0.0.1' => ['https://127.0.0.1/hooks'],
            'loopback range' => ['https://127.10.0.5/hooks'],
            'ipv6 loopback' => ['https://[::1]/hooks'],
            'private 10/8' => ['https://10.0.0.5/hooks'],
            'private 172.16/12' => ['https://172.16.5.5/hooks'],
            'private 192.168/16' => ['https://192.168.1.1/hooks'],
            'link-local metadata range' => ['https://169.254.1.1/hooks'],
            'cloud metadata literal' => ['https://169.254.169.254/latest/meta-data'],
            'ipv6 unique-local' => ['https://[fc00::1]/hooks'],
            'ipv6 link-local' => ['https://[fe80::1]/hooks'],
        ];
    }

    public function test_plain_http_is_rejected_unless_explicitly_allowed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->assertSafe('http://example.com/hooks');
    }

    public function test_plain_http_is_accepted_when_explicitly_allowed_for_testing(): void
    {
        $this->service->assertSafe('http://example.com/hooks', allowInsecureHttpForTesting: true);
        $this->assertTrue(true);
    }

    public function test_is_safe_returns_false_rather_than_throwing(): void
    {
        $this->assertFalse($this->service->isSafe('https://127.0.0.1/hooks'));
        $this->assertTrue($this->service->isSafe('https://example.com/hooks'));
    }

    /**
     * Required test: no DNS or network calls in validation (correction
     * #5). We cannot literally intercept syscalls in this environment,
     * so this is a structural/source-inspection test proving the
     * service's source never references a DNS resolver function.
     */
    public function test_validation_service_never_calls_a_dns_resolver(): void
    {
        $source = file_get_contents(app_path('Services/WebhookDestinationValidationService.php'));

        foreach (['gethostbyname(', 'dns_get_record(', 'checkdnsrr(', 'gethostbynamel('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "WebhookDestinationValidationService must never call {$forbidden}");
        }
    }
}
