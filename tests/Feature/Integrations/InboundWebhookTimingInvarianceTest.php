<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\EntitlementSource;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Providers\TestProvider\TestProvider;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Integrations\Services\IntegrationCredentialService;
use App\Integrations\Services\IntegrationOAuthStateService;
use App\Integrations\Services\ProviderConnectionService;
use App\Integrations\Support\GmailMailboxRoutingService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Integrations\Support\PkceService;
use App\Integrations\Support\ProviderRedirectUrlValidator;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use App\Services\EmailBodyEncryptionService;
use App\Services\EncryptionKeyService;
use App\Services\EntitlementService;
use App\Services\IntegrationEntitlementPolicyService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

        // Checkpoint 1 (FirmsVault Live Integrations): InboundWebhookController
        // now resolves the provider via ProviderRegistry/ProviderKey FIRST,
        // before anything else — without this, every real HTTP request this
        // test makes through the controller collapses to a 401 regardless of
        // routing token/signature validity. Only the 'test' key is
        // registered here — 'unknownprovider' used by this file's own
        // early-exit comparison stays deliberately unregistered. Mirrors
        // InboundWebhookLifecycleRevalidationTest::setUp()'s identical,
        // already-established override.
        config(['integrations.providers' => [ProviderKey::Test->value => TestProvider::class]]);
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
     * CHECKPOINT 1 addition (FirmsVault Live Integrations, security
     * review Finding 5): confirms the tolerance-band measurement above
     * genuinely INCLUDES RecordWebhookVerificationFailureJob's real
     * synchronous execution (this suite's phpunit.xml sets
     * QUEUE_CONNECTION=sync, so a ShouldQueue job dispatched here
     * actually runs its handle() — including the real DB INSERT — within
     * the same request, not skipped/deferred) — i.e. the measurement
     * above is not silently exempt from the very overhead Finding 5
     * raised the concern about. All three rejection paths dispatch the
     * job (confirmed directly, not inferred), so the comparison stays
     * apples-to-apples.
     */
    public function test_all_three_measured_rejection_paths_genuinely_dispatch_and_execute_the_verification_failure_job(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = $this->eventBody();

        $countBefore = DB::table('integration_webhook_verification_failures')->count();

        $this->postWebhook('unknownprovider', [
            'X-Test-Provider-Connection-Token' => Str::random(43),
            'X-Test-Provider-Signature' => 'v1='.str_repeat('a', 64),
            'X-Test-Provider-Timestamp' => (string) now()->getTimestamp(),
        ], $body);

        $this->postWebhook('test', [
            'X-Test-Provider-Connection-Token' => Str::random(43),
            'X-Test-Provider-Signature' => 'v1='.str_repeat('a', 64),
            'X-Test-Provider-Timestamp' => (string) now()->getTimestamp(),
        ], $body);

        $this->postWebhook('test', [
            'X-Test-Provider-Connection-Token' => $fixture['rawToken'],
            'X-Test-Provider-Signature' => 'v1='.str_repeat('9', 64),
            'X-Test-Provider-Timestamp' => (string) now()->getTimestamp(),
        ], $body);

        $countAfter = DB::table('integration_webhook_verification_failures')->count();

        $this->assertSame(
            $countBefore + 3,
            $countAfter,
            'All three rejection paths measured by this file\'s timing-invariance assertion must genuinely dispatch and execute RecordWebhookVerificationFailureJob — otherwise the tolerance-band comparison above would not actually be proving what it claims to prove.'
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
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        $connection = FirmIntegration::factory()->forFirm($firm)->create(['status' => ConnectionStatus::Active->value]);
        $rawToken = $this->connectionService()->enableWebhookRouting($connection, $this->webhookRoutingActorUserId($connection));

        $secret = 'wh-secret-'.Str::random(32);
        $this->runWithFirmContext($firm, fn () => $this->credentialService()->store($connection->fresh(), CredentialType::WebhookSigningSecret, $secret));

        return ['firm' => $firm, 'connection' => $connection, 'rawToken' => $rawToken, 'secret' => $secret];
    }

    private function credentialService(): IntegrationCredentialService
    {
        return new IntegrationCredentialService(new EmailBodyEncryptionService(new EncryptionKeyService), new TimelineEventRecorder);
    }

    private function connectionService(): ProviderConnectionService
    {
        return new ProviderConnectionService(
            new IntegrationOAuthStateService(
                new EmailBodyEncryptionService(new EncryptionKeyService),
                new PkceService,
                new ProviderRedirectUrlValidator,
            ),
            $this->credentialService(),
            new IntegrationAccessPolicyService(new TimelineEventRecorder),
            new ProviderRegistry,
            new OutboundProviderHttpClient,
            new ProviderRedirectUrlValidator,
            new TimelineEventRecorder,
            // Checkpoint 10 addition (frozen design §4): ProviderConnectionService's
            // constructor gained this 8th, required dependency — every
            // manual construction site in this file must supply it.
            app(IntegrationEntitlementPolicyService::class),
            // Checkpoint 3 addition (FirmsVault Live Integrations,
            // Google Workspace): ProviderConnectionService's constructor
            // gained this 9th, required dependency -- every manual
            // construction site in this file must supply it.
            app(GmailMailboxRoutingService::class),
        );
    }

    /**
     * Checkpoint 10 addition: enableWebhookRouting()/disableWebhookRouting()
     * now require an authorized $currentUserId. FirmIntegrationFactory
     * already creates an Active, Attorney-role FirmUser as
     * connected_by_firm_user_id for every connection it builds, so that
     * same FirmUser's user_id is reused here as the acting user rather
     * than minting a second, unrelated one.
     */
    private function webhookRoutingActorUserId(FirmIntegration $connection): int
    {
        return $this->runWithFirmContext(
            $connection->firm_id,
            fn () => $connection->connectedByFirmUser->user_id,
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
        // Checkpoint 1 (design §6): the controller's new content-type
        // allowlist rejects Symfony's default 'application/x-www-form-urlencoded'
        // for raw POST content with no explicit Content-Type — every real
        // webhook sender sets this, so this is the correct fixture fix
        // (see InboundWebhookAuditLoggerTest::postWebhook() for the full
        // rationale). This applies uniformly to all three timing-comparison
        // branches below, so it does not distort the relative comparison.
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', "/webhooks/integrations/{$provider}", [], [], [], $server, $body);
    }
}
