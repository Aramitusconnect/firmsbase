<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Integrations\Services\IntegrationCredentialService;
use App\Integrations\Services\IntegrationOAuthStateService;
use App\Integrations\Services\ProviderConnectionService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Integrations\Support\PkceService;
use App\Integrations\Support\ProviderRedirectUrlValidator;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use App\Services\EmailBodyEncryptionService;
use App\Services\EncryptionKeyService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * InboundWebhookTimingInvarianceTest — Checkpoint 7's required
 * timing-oracle mitigation
 * (reviews/checkpoint-07/frozen-design-post-security-review.md §9).
 *
 * This is inherently a soft/statistical assertion in a shared test
 * environment (PHPUnit process, real Postgres connection, no isolated
 * hardware) — CPU scheduling noise, JIT warmup, and connection-pool
 * jitter all contribute variance unrelated to the code path itself.
 * To avoid CI flakiness while still proving the mitigation exists and
 * is load-bearing:
 *   - each scenario is sampled MANY times and AVERAGED, not compared
 *     on a single sample;
 *   - the tolerance band is generous (a fixed absolute floor PLUS a
 *     wide relative multiplier) — chosen to catch a genuine multiple-
     *     orders-of-magnitude regression (e.g. someone removing
 *     performConstantWorkPadding() entirely, which the frozen design's
 *     own risk analysis estimates at a "1-2 order-of-magnitude" gap)
 *     while tolerating ordinary test-environment noise;
 *   - the test is written to fail closed only on a LARGE, structural
 *     regression, never on ordinary jitter — this is the same posture
 *     `TrustConcurrencyLockServiceTest`'s own concurrency disclaimers
 *     already establish for timing-sensitive proofs in this codebase.
 */
final class InboundWebhookTimingInvarianceTest extends TestCase
{
    use RefreshDatabase;

    private const SAMPLES = 25;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_unknown_provider_unknown_token_and_invalid_signature_rejections_fall_within_a_generous_tolerance_band(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();

        $unknownProviderAvg = $this->averageMicroseconds(function () use ($body) {
            $this->postWebhook('unknownprovider', [
                'X-Test-Provider-Connection-Token' => Str::random(43),
                'X-Test-Provider-Signature' => 'v1='.str_repeat('a', 64),
                'X-Test-Provider-Timestamp' => (string) now()->getTimestamp(),
            ], $body);
        });

        $unknownTokenAvg = $this->averageMicroseconds(function () use ($body) {
            $this->postWebhook('test', [
                'X-Test-Provider-Connection-Token' => Str::random(43),
                'X-Test-Provider-Signature' => 'v1='.str_repeat('a', 64),
                'X-Test-Provider-Timestamp' => (string) now()->getTimestamp(),
            ], $body);
        });

        $invalidSignatureAvg = $this->averageMicroseconds(function () use ($fixture, $body) {
            $this->postWebhook('test', [
                'X-Test-Provider-Connection-Token' => $fixture['rawToken'],
                'X-Test-Provider-Signature' => 'v1='.str_repeat('9', 64),
                'X-Test-Provider-Timestamp' => (string) now()->getTimestamp(),
            ], $body);
        });

        $samples = [$unknownProviderAvg, $unknownTokenAvg, $invalidSignatureAvg];
        $min = min($samples);
        $max = max($samples);

        // Generous absolute floor (10ms) avoids dividing by a near-zero
        // baseline, plus a wide 8x relative multiplier — chosen to
        // reliably catch a genuinely missing constant-work-padding
        // mitigation (a 1-2 order-of-magnitude gap per the frozen
        // design's own risk estimate) while tolerating this shared
        // test environment's ordinary scheduling noise.
        $toleranceFloor = 0.010;
        $ratio = $max / max($min, $toleranceFloor);

        $this->assertLessThan(
            8.0,
            $ratio,
            sprintf(
                'Timing gap between early-exit paths too large (unknown provider=%.4fs, unknown token=%.4fs, invalid signature=%.4fs) — the constant-work padding mitigation (§9) may be missing or broken.',
                $unknownProviderAvg,
                $unknownTokenAvg,
                $invalidSignatureAvg,
            )
        );
    }

    /**
     * @return float average wall-clock seconds per call
     */
    private function averageMicroseconds(callable $callback): float
    {
        $total = 0.0;

        for ($i = 0; $i < self::SAMPLES; $i++) {
            $start = microtime(true);
            $callback();
            $total += microtime(true) - $start;
        }

        return $total / self::SAMPLES;
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function activeConnectionWithWebhookSecret(): array
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        $connection = FirmIntegration::factory()->forFirm($firm)->create(['status' => ConnectionStatus::Active->value]);
        $rawToken = $this->connectionService()->enableWebhookRouting($connection);

        $secret = 'wh-secret-'.Str::random(32);
        $this->runWithFirmContext($firm, fn () => $this->credentialService()->store($connection->fresh(), CredentialType::WebhookSigningSecret, $secret));

        return ['firm' => $firm, 'connection' => $connection, 'rawToken' => $rawToken, 'secret' => $secret];
    }

    private function credentialService(): IntegrationCredentialService
    {
        return new IntegrationCredentialService(new EmailBodyEncryptionService(new EncryptionKeyService()));
    }

    private function connectionService(): ProviderConnectionService
    {
        return new ProviderConnectionService(
            new IntegrationOAuthStateService(
                new EmailBodyEncryptionService(new EncryptionKeyService()),
                new PkceService(),
                new ProviderRedirectUrlValidator(),
            ),
            $this->credentialService(),
            new IntegrationAccessPolicyService(),
            new \App\Integrations\Core\ProviderRegistry(),
            new OutboundProviderHttpClient(),
            new ProviderRedirectUrlValidator(),
            new TimelineEventRecorder(),
        );
    }

    private function eventBody(): string
    {
        return json_encode([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'test.resource.created',
            'payload' => ['foo' => 'bar'],
        ]);
    }

    private function postWebhook(string $provider, array $headers, string $body): TestResponse
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', "/webhooks/integrations/{$provider}", [], [], [], $server, $body);
    }
}
