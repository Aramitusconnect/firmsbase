<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\EntitlementSource;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\InboundWebhookAuditLogger;
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
use App\Services\EntitlementService;
use App\Services\IntegrationEntitlementPolicyService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use ReflectionClass;
use Tests\TestCase;

/**
 * InboundWebhookAuditLoggerTest — Checkpoint 13 (frozen-test-closure-
 * plan.md §4). Closes the previously-zero test coverage on
 * App\Integrations\Services\InboundWebhookAuditLogger (the platform-only
 * pre-resolution audit sink, Checkpoint 7 §14).
 *
 * Proves:
 *  - stripForbiddenKeys() genuinely removes EVERY FORBIDDEN_CONTEXT_KEYS
 *    entry (driven directly off the class's own denylist via reflection,
 *    so it can never drift out of sync), case-insensitively, and
 *    recursively into nested arrays.
 *  - an allowed event type's context passes through unmodified.
 *  - record() refuses any event name outside the 11 frozen names.
 *  - the real InboundWebhookController path invokes this logger safely on
 *    both a successful (202) and a rejected (401) request, writing the
 *    audit line while never leaking the raw token / signature / secret /
 *    body.
 */
final class InboundWebhookAuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    private string $auditLogPath;

    protected function setUp(): void
    {
        parent::setUp();

        // The logger's own fixed, dedicated file (Log::build path in the
        // class). Start each test with a clean slate so assertions never
        // race a line another test wrote.
        $this->auditLogPath = storage_path('logs/integration-webhook-audit.log');

        if (file_exists($this->auditLogPath)) {
            @unlink($this->auditLogPath);
        }
    }

    private function auditLogContents(): string
    {
        $this->assertFileExists($this->auditLogPath, 'The audit logger must have written its dedicated log file.');

        return (string) file_get_contents($this->auditLogPath);
    }

    /**
     * @return string[] the class's own private FORBIDDEN_CONTEXT_KEYS.
     */
    private function forbiddenKeys(): array
    {
        $keys = (new ReflectionClass(InboundWebhookAuditLogger::class))->getConstant('FORBIDDEN_CONTEXT_KEYS');
        $this->assertIsArray($keys);
        $this->assertNotEmpty($keys);

        return $keys;
    }

    // ------------------------------------------------------------
    // Denylist stripping — every forbidden key, driven off the class's
    // own constant so this can never silently fall out of sync with it.
    // ------------------------------------------------------------

    public function test_strip_removes_every_forbidden_context_key_before_logging(): void
    {
        $forbidden = $this->forbiddenKeys();

        // Give each forbidden key a UNIQUE sentinel value; add two allowed
        // keys with their own sentinels.
        $context = [
            'provider' => 'ALLOWED_PROVIDER_SENTINEL',
            'firm_integration_id' => 'ALLOWED_FII_SENTINEL',
        ];
        $sentinelByKey = [];
        foreach ($forbidden as $i => $key) {
            $sentinel = "FORBIDDEN_SENTINEL_{$i}_".strtoupper(str_replace(' ', '', $key));
            $context[$key] = $sentinel;
            $sentinelByKey[$key] = $sentinel;
        }

        (new InboundWebhookAuditLogger())->record(InboundWebhookAuditLogger::EVENT_REQUEST_RECEIVED, $context);

        $contents = $this->auditLogContents();

        foreach ($sentinelByKey as $key => $sentinel) {
            $this->assertStringNotContainsString(
                $sentinel,
                $contents,
                "The value for forbidden key '{$key}' must be stripped and never written to the audit log."
            );
        }

        // The allowed keys' values must survive — proving the strip is
        // surgical (removes only the denylisted keys), not a blanket drop.
        $this->assertStringContainsString('ALLOWED_PROVIDER_SENTINEL', $contents);
        $this->assertStringContainsString('ALLOWED_FII_SENTINEL', $contents);
    }

    public function test_forbidden_keys_are_stripped_case_insensitively(): void
    {
        $context = [
            'provider' => 'ALLOWED_CASE_SENTINEL',
            'Signature' => 'MIXEDCASE_SIGNATURE_SENTINEL',
            'SECRET' => 'UPPERCASE_SECRET_SENTINEL',
            'Authorization' => 'MIXEDCASE_AUTH_SENTINEL',
            'RAW_BODY' => 'UPPERCASE_BODY_SENTINEL',
        ];

        (new InboundWebhookAuditLogger())->record(InboundWebhookAuditLogger::EVENT_SIGNATURE_REJECTED, $context);

        $contents = $this->auditLogContents();

        $this->assertStringNotContainsString('MIXEDCASE_SIGNATURE_SENTINEL', $contents);
        $this->assertStringNotContainsString('UPPERCASE_SECRET_SENTINEL', $contents);
        $this->assertStringNotContainsString('MIXEDCASE_AUTH_SENTINEL', $contents);
        $this->assertStringNotContainsString('UPPERCASE_BODY_SENTINEL', $contents);
        $this->assertStringContainsString('ALLOWED_CASE_SENTINEL', $contents, 'The denylist match is on key name only — an allowed key must still pass regardless of case handling.');
    }

    public function test_forbidden_keys_nested_inside_arrays_are_also_stripped(): void
    {
        $context = [
            'provider' => 'ALLOWED_NESTED_SENTINEL',
            'diagnostic' => [
                'stage' => 'ALLOWED_STAGE_SENTINEL',
                'signature' => 'NESTED_SIGNATURE_SENTINEL',
                'deeper' => [
                    'webhook_secret' => 'DEEPLY_NESTED_SECRET_SENTINEL',
                    'note' => 'ALLOWED_NOTE_SENTINEL',
                ],
            ],
        ];

        (new InboundWebhookAuditLogger())->record(InboundWebhookAuditLogger::EVENT_PROCESSING_FAILED, $context);

        $contents = $this->auditLogContents();

        $this->assertStringNotContainsString('NESTED_SIGNATURE_SENTINEL', $contents, 'A forbidden key nested one level deep must still be stripped.');
        $this->assertStringNotContainsString('DEEPLY_NESTED_SECRET_SENTINEL', $contents, 'A forbidden key nested two levels deep must still be stripped.');
        $this->assertStringContainsString('ALLOWED_STAGE_SENTINEL', $contents);
        $this->assertStringContainsString('ALLOWED_NOTE_SENTINEL', $contents, 'An allowed sibling of a nested forbidden key must survive.');
    }

    // ------------------------------------------------------------
    // Allowed context passes through unmodified.
    // ------------------------------------------------------------

    public function test_an_allowed_events_context_passes_through_unmodified(): void
    {
        $context = [
            'provider' => 'test',
            'firm_integration_id' => 987654,
            'outcome' => 'verified',
            'duplicate' => false,
        ];

        (new InboundWebhookAuditLogger())->record(InboundWebhookAuditLogger::EVENT_ROUTE_IDENTITY_RESOLVED, $context);

        $contents = $this->auditLogContents();

        $this->assertStringContainsString(InboundWebhookAuditLogger::EVENT_ROUTE_IDENTITY_RESOLVED, $contents);
        $this->assertStringContainsString('"provider":"test"', $contents);
        $this->assertStringContainsString('987654', $contents);
        $this->assertStringContainsString('"outcome":"verified"', $contents);
        $this->assertStringContainsString('"duplicate":false', $contents);
    }

    // ------------------------------------------------------------
    // Event-name allowlist — closed set of 11.
    // ------------------------------------------------------------

    public function test_record_refuses_an_unknown_event_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new InboundWebhookAuditLogger())->record('integration_webhook.some_unreviewed_new_event', ['provider' => 'test']);
    }

    public function test_all_eleven_frozen_event_names_are_accepted(): void
    {
        $allowed = (new ReflectionClass(InboundWebhookAuditLogger::class))->getConstant('ALLOWED_EVENT_NAMES');
        $this->assertIsArray($allowed);
        $this->assertCount(11, $allowed, 'The frozen allowlist must contain exactly the 11 documented event names.');

        $logger = new InboundWebhookAuditLogger();
        foreach ($allowed as $eventName) {
            $logger->record($eventName, ['provider' => 'test']);
            $this->addToAssertionCount(1);
        }

        $contents = $this->auditLogContents();
        foreach ($allowed as $eventName) {
            $this->assertStringContainsString($eventName, $contents);
        }
    }

    // ------------------------------------------------------------
    // Real controller path — success and rejection both invoke the
    // logger safely (writes the line, leaks no secret material).
    // ------------------------------------------------------------

    public function test_a_rejected_request_invokes_the_logger_safely(): void
    {
        // No fixture needed: an unknown routing token resolves to null, so
        // the controller records request_received + signature_rejected and
        // returns 401 — exercising the pre-resolution audit path directly.
        $secretMaterial = 'REJECT-PATH-SECRET-'.Str::random(24);

        $response = $this->postWebhook('test', [
            'X-Test-Provider-Connection-Token' => 'wholly-unknown-token-'.Str::random(16),
            'X-Test-Provider-Signature' => 'v1='.str_repeat('a', 64),
            'X-Test-Provider-Timestamp' => (string) now()->getTimestamp(),
        ], json_encode(['event_id' => (string) Str::uuid(), 'secret_body_field' => $secretMaterial]));

        $response->assertStatus(401);

        $contents = $this->auditLogContents();
        $this->assertStringContainsString(InboundWebhookAuditLogger::EVENT_REQUEST_RECEIVED, $contents, 'Every real request must log request_received.');
        $this->assertStringContainsString(InboundWebhookAuditLogger::EVENT_SIGNATURE_REJECTED, $contents, 'A request with an unresolvable routing token must log signature_rejected.');
        $this->assertStringNotContainsString($secretMaterial, $contents, 'The raw request body content must never appear in the audit log.');
    }

    public function test_a_successful_request_invokes_the_logger_safely(): void
    {
        $fixture = $this->activeConnectionWithWebhookSecret();
        $body = json_encode(['event_id' => (string) Str::uuid(), 'event_type' => 'test.resource.created', 'payload' => ['foo' => 'bar']]);
        $timestamp = now()->getTimestamp();
        $signature = 'v1='.hash_hmac('sha256', 'v1:'.$timestamp.'.'.$body, $fixture['secret']);

        $response = $this->postWebhook('test', [
            'X-Test-Provider-Connection-Token' => $fixture['rawToken'],
            'X-Test-Provider-Signature' => $signature,
            'X-Test-Provider-Timestamp' => (string) $timestamp,
        ], $body);

        $response->assertStatus(202);

        $contents = $this->auditLogContents();
        $this->assertStringContainsString(InboundWebhookAuditLogger::EVENT_REQUEST_RECEIVED, $contents);
        $this->assertStringContainsString(InboundWebhookAuditLogger::EVENT_SIGNATURE_VERIFIED, $contents, 'A correctly-signed request must log signature_verified.');

        // The routing token, the signature, and the webhook secret must
        // never appear anywhere in the audit log.
        $this->assertStringNotContainsString($fixture['rawToken'], $contents, 'The raw routing token must never be logged.');
        $this->assertStringNotContainsString($fixture['secret'], $contents, 'The webhook signing secret must never be logged.');
        $this->assertStringNotContainsString($signature, $contents, 'The signature value must never be logged.');
    }

    // ------------------------------------------------------------
    // Helpers (webhook fixture, mirroring InboundWebhookReplayProtectionTest)
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
            new IntegrationAccessPolicyService(new TimelineEventRecorder()),
            new \App\Integrations\Core\ProviderRegistry(),
            new OutboundProviderHttpClient(),
            new ProviderRedirectUrlValidator(),
            new TimelineEventRecorder(),
            app(IntegrationEntitlementPolicyService::class),
        );
    }

    private function webhookRoutingActorUserId(FirmIntegration $connection): int
    {
        return $this->runWithFirmContext(
            $connection->firm_id,
            fn () => $connection->connectedByFirmUser->user_id,
        );
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
