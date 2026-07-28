<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Providers\GoogleWorkspace;

use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Listeners\DispatchPullSyncOnVerifiedWebhookEvent;
use App\Integrations\Providers\GoogleWorkspace\GoogleWorkspaceProvider;
use Google\Auth\AccessToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * GoogleWorkspaceProviderWebhookTest — FirmsVault Live Integrations,
 * Checkpoint 3 (test-writer pass). Structural, per-method coverage of
 * GoogleWorkspaceProvider's SupportsWebhooksContract surface
 * (webhookEventTypes()/detectSubscriptionValidationChallenge()/
 * extractRoutingIdentifier()/verifyInboundSignature()/parseInboundEvent()),
 * mirroring Microsoft365ProviderWebhookTest.php's structure and rigor.
 *
 * Scope split, deliberate: this file proves the STRUCTURAL/discrimination
 * behavior across all three Google sub-APIs (Calendar/Drive channel
 * notifications vs. Gmail Pub/Sub delivery) and the event_type
 * construction contract with DispatchPullSyncOnVerifiedWebhookEvent
 * (the exact class of bug checkpoint2-diff-review.md found and fixed for
 * Microsoft 365 — this design applies that fix from the first
 * implementation, and this file proves it holds for all of Google's
 * wire shapes). The EXHAUSTIVE Gmail OIDC JWT claim-validation security
 * matrix (checkpoint3-design-sync-webhooks.md §10.1 — wrong audience,
 * wrong issuer, wrong service-account email, email_verified=false,
 * expired/future-issued JWT, malformed Bearer header, etc.) lives in the
 * dedicated tests/Unit/Integrations/Support/GoogleOidcJwtVerificationTest.php
 * instead, so that exhaustive matrix does not get lost/diluted among this
 * file's broader structural coverage.
 *
 * `Google\Auth\AccessToken` is swapped for a test double via
 * app()->instance() in every scenario that reaches
 * verifyInboundSignature()'s Gmail branch — no test in this file ever
 * reaches Google's real cert endpoint (checkpoint3-security-review.md
 * Finding 2's required test-double mechanism).
 */
final class GoogleWorkspaceProviderWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function provider(): GoogleWorkspaceProvider
    {
        return app(GoogleWorkspaceProvider::class);
    }

    private function bindAccessTokenFake(?array $claims, ?\Throwable $throws = null): void
    {
        app()->instance(AccessToken::class, new class($claims, $throws) extends AccessToken
        {
            public function __construct(private readonly ?array $claims, private readonly ?\Throwable $throws) {}

            public function verify($token, array $options = [])
            {
                if ($this->throws !== null) {
                    throw $this->throws;
                }

                return $this->claims ?? false;
            }
        });
    }

    private function validGmailClaims(): array
    {
        return [
            'iss' => 'https://accounts.google.com',
            'email' => 'push@unit-test.iam.gserviceaccount.com',
            'email_verified' => true,
            'iat' => now()->getTimestamp(),
        ];
    }

    private function configurePubSubAudience(): void
    {
        config([
            'integrations.oauth_apps.googleworkspace.pubsub_push_audience' => 'unit-test-audience',
            'integrations.oauth_apps.googleworkspace.pubsub_push_service_account_email' => 'push@unit-test.iam.gserviceaccount.com',
        ]);
    }

    // ------------------------------------------------------------
    // webhookEventTypes()
    // ------------------------------------------------------------

    public function test_webhook_event_types_returns_the_closed_union_of_every_literal_this_provider_can_emit(): void
    {
        $types = $this->provider()->webhookEventTypes();

        $this->assertSame(
            ['sync', 'exists', 'not_exists', 'add', 'remove', 'update', 'trash', 'untrash', 'change', 'history_changed'],
            $types
        );
    }

    // ------------------------------------------------------------
    // detectSubscriptionValidationChallenge()
    // ------------------------------------------------------------

    public function test_detect_subscription_validation_challenge_always_returns_null(): void
    {
        $provider = $this->provider();

        $this->assertNull($provider->detectSubscriptionValidationChallenge([], []));
        $this->assertNull($provider->detectSubscriptionValidationChallenge(['validationToken' => 'anything'], []));
        $this->assertNull($provider->detectSubscriptionValidationChallenge(['hub_challenge' => 'echo-me'], ['X-Goog-Resource-State' => 'sync']));
    }

    // ------------------------------------------------------------
    // extractRoutingIdentifier()
    // ------------------------------------------------------------

    public function test_extract_routing_identifier_returns_the_channel_token_for_a_calendar_or_drive_notification(): void
    {
        $result = $this->provider()->extractRoutingIdentifier('', [
            'X-Goog-Resource-State' => 'exists',
            'X-Goog-Channel-Token' => 'connection-routing-token-abc',
        ]);

        $this->assertSame('connection-routing-token-abc', $result);
    }

    public function test_extract_routing_identifier_is_case_insensitive_on_header_names(): void
    {
        $result = $this->provider()->extractRoutingIdentifier('', [
            'x-goog-resource-state' => 'exists',
            'x-goog-channel-token' => 'connection-routing-token-abc',
        ]);

        $this->assertSame('connection-routing-token-abc', $result);
    }

    public function test_extract_routing_identifier_returns_null_when_the_channel_token_is_missing_or_empty(): void
    {
        $provider = $this->provider();

        $this->assertNull($provider->extractRoutingIdentifier('', ['X-Goog-Resource-State' => 'exists']));
        $this->assertNull($provider->extractRoutingIdentifier('', ['X-Goog-Resource-State' => 'exists', 'X-Goog-Channel-Token' => '']));
    }

    public function test_extract_routing_identifier_returns_the_unverified_email_address_for_a_gmail_notification(): void
    {
        $body = $this->gmailPubSubEnvelope('msg-1', ['emailAddress' => 'user@firm-domain.test', 'historyId' => 555]);

        $result = $this->provider()->extractRoutingIdentifier($body, []);

        $this->assertSame('user@firm-domain.test', $result);
    }

    public function test_extract_routing_identifier_returns_null_for_a_gmail_notification_with_no_email_address(): void
    {
        $body = $this->gmailPubSubEnvelope('msg-1', ['historyId' => 555]);

        $this->assertNull($this->provider()->extractRoutingIdentifier($body, []));
    }

    public function test_extract_routing_identifier_never_throws_on_malformed_gmail_bodies(): void
    {
        $provider = $this->provider();

        $this->assertNull($provider->extractRoutingIdentifier('{not valid json', []));
        $this->assertNull($provider->extractRoutingIdentifier('', []));
        $this->assertNull($provider->extractRoutingIdentifier('null', []));
        $this->assertNull($provider->extractRoutingIdentifier('{"message": {"data": "not-valid-base64url!!!"}}', []));
    }

    // ------------------------------------------------------------
    // verifyInboundSignature()
    // ------------------------------------------------------------

    public function test_verify_inbound_signature_returns_true_for_any_calendar_or_drive_notification_carrying_a_channel_token(): void
    {
        $provider = $this->provider();

        $this->assertTrue($provider->verifyInboundSignature('', ['X-Goog-Channel-Token' => 'anything-at-all']));
        $this->assertTrue($provider->verifyInboundSignature('garbage-body', ['X-Goog-Channel-Token' => 'x']));
    }

    public function test_verify_inbound_signature_delegates_to_oidc_verification_when_no_channel_token_is_present(): void
    {
        $this->configurePubSubAudience();
        $this->bindAccessTokenFake($this->validGmailClaims());

        $result = $this->provider()->verifyInboundSignature('{}', ['Authorization' => 'Bearer a-fixture-jwt']);

        $this->assertTrue($result);
    }

    public function test_verify_inbound_signature_returns_false_for_a_gmail_request_with_no_authorization_header_at_all(): void
    {
        $this->configurePubSubAudience();
        $this->bindAccessTokenFake($this->validGmailClaims());

        $this->assertFalse($this->provider()->verifyInboundSignature('{}', []));
    }

    // ------------------------------------------------------------
    // parseInboundEvent() — Calendar/Drive branch
    // ------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function channelNotificationProvider(): array
    {
        return [
            'calendar exists' => ['https://www.googleapis.com/calendar/v3/calendars/primary/events', 'exists', 'calendar_event:exists', ResourceType::CalendarEvent->value],
            'calendar not_exists' => ['https://www.googleapis.com/calendar/v3/calendars/primary/events', 'not_exists', 'calendar_event:not_exists', ResourceType::CalendarEvent->value],
            'drive add' => ['https://www.googleapis.com/drive/v3/changes', 'add', 'document:add', ResourceType::Document->value],
            'drive trash' => ['https://www.googleapis.com/drive/v3/changes', 'trash', 'document:trash', ResourceType::Document->value],
            'drive untrash' => ['https://www.googleapis.com/drive/v3/changes', 'untrash', 'document:untrash', ResourceType::Document->value],
        ];
    }

    #[DataProvider('channelNotificationProvider')]
    public function test_parse_inbound_event_produces_a_resource_type_prefixed_event_type_the_listener_can_map(
        string $resourceUri,
        string $resourceState,
        string $expectedEventType,
        string $expectedResourceType,
    ): void {
        $parsed = $this->provider()->parseInboundEvent('', [
            'X-Goog-Resource-State' => $resourceState,
            'X-Goog-Resource-Uri' => $resourceUri,
            'X-Goog-Channel-Id' => 'channel-1',
            'X-Goog-Resource-Id' => 'resource-1',
            'X-Goog-Message-Number' => '1',
        ]);

        $this->assertNotNull($parsed['event_id']);
        $this->assertSame($expectedEventType, $parsed['event_type']);

        $resolved = $this->invokeListenerMapping($parsed['event_type']);

        $this->assertNotNull($resolved, "event_type \"{$parsed['event_type']}\" must map to a ResourceType — this is the regression this test exists to prevent.");
        $this->assertSame($expectedResourceType, $resolved->value);
    }

    public function test_parse_inbound_event_treats_the_sync_handshake_as_a_lifecycle_notification_the_listener_skips(): void
    {
        $parsed = $this->provider()->parseInboundEvent('', [
            'X-Goog-Resource-State' => 'sync',
            'X-Goog-Resource-Uri' => 'https://www.googleapis.com/calendar/v3/calendars/primary/events',
            'X-Goog-Channel-Id' => 'channel-1',
            'X-Goog-Resource-Id' => 'resource-1',
            'X-Goog-Message-Number' => '0',
        ]);

        $this->assertSame('lifecycle:calendar_event_sync', $parsed['event_type']);
        $this->assertNull($this->invokeListenerMapping($parsed['event_type']));
    }

    public function test_parse_inbound_event_with_an_unrecognized_resource_uri_produces_an_unrecognized_lifecycle_prefix(): void
    {
        $parsed = $this->provider()->parseInboundEvent('', [
            'X-Goog-Resource-State' => 'exists',
            'X-Goog-Resource-Uri' => 'https://www.googleapis.com/somethingCompletelyUnrelated',
            'X-Goog-Channel-Id' => 'channel-1',
            'X-Goog-Resource-Id' => 'resource-1',
            'X-Goog-Message-Number' => '1',
        ]);

        $this->assertSame('lifecycle:unrecognized_channel', $parsed['event_type']);
        $this->assertNull($this->invokeListenerMapping($parsed['event_type']));
    }

    public function test_parse_inbound_event_is_deterministic_for_identical_channel_headers_true_redelivery(): void
    {
        $headers = [
            'X-Goog-Resource-State' => 'exists',
            'X-Goog-Resource-Uri' => 'https://www.googleapis.com/calendar/v3/calendars/primary/events',
            'X-Goog-Channel-Id' => 'channel-1',
            'X-Goog-Resource-Id' => 'resource-1',
            'X-Goog-Message-Number' => '7',
        ];

        $first = $this->provider()->parseInboundEvent('', $headers);
        $second = $this->provider()->parseInboundEvent('', $headers);

        $this->assertSame($first['event_id'], $second['event_id']);
    }

    // ------------------------------------------------------------
    // parseInboundEvent() — Gmail Pub/Sub branch
    // ------------------------------------------------------------

    public function test_parse_inbound_event_maps_a_valid_gmail_notification_to_message_history_changed(): void
    {
        $body = $this->gmailPubSubEnvelope('pubsub-message-id-1', ['emailAddress' => 'user@firm.test', 'historyId' => 998877]);

        $parsed = $this->provider()->parseInboundEvent($body, []);

        $this->assertSame('pubsub-message-id-1', $parsed['event_id'], 'Pub/Sub\'s own messageId is a real, stable, guaranteed-unique per-delivery identifier — used verbatim, never re-derived.');
        $this->assertSame(ResourceType::Message->value.':history_changed', $parsed['event_type']);
        $this->assertSame(['history_id' => 998877], $parsed['payload']);

        $resolved = $this->invokeListenerMapping($parsed['event_type']);
        $this->assertSame(ResourceType::Message->value, $resolved?->value);
    }

    public function test_parse_inbound_event_rejects_a_malformed_pub_sub_envelope(): void
    {
        $expected = ['event_id' => null, 'event_type' => null, 'payload' => []];

        $provider = $this->provider();

        $this->assertSame($expected, $provider->parseInboundEvent('not valid json at all', []));
        $this->assertSame($expected, $provider->parseInboundEvent('', []));
        $this->assertSame($expected, $provider->parseInboundEvent('null', []));
        $this->assertSame($expected, $provider->parseInboundEvent('{"no_message_key": true}', []));
        $this->assertSame($expected, $provider->parseInboundEvent('{"message": {"messageId": ""}}', []));
    }

    public function test_parse_inbound_event_rejects_a_malformed_base64_data_payload(): void
    {
        $body = json_encode(['message' => ['messageId' => 'msg-x', 'data' => 'not-valid-base64url!!!']]);

        $parsed = $this->provider()->parseInboundEvent($body, []);

        $this->assertNull($parsed['event_id']);
        $this->assertNull($parsed['event_type']);
        $this->assertSame([], $parsed['payload']);
    }

    public function test_parse_inbound_event_rejects_a_payload_missing_email_address(): void
    {
        $body = $this->gmailPubSubEnvelope('msg-x', ['historyId' => 123]);

        $parsed = $this->provider()->parseInboundEvent($body, []);

        $this->assertNull($parsed['event_id']);
        $this->assertNull($parsed['event_type']);
    }

    public function test_parse_inbound_event_rejects_a_payload_missing_history_id(): void
    {
        $body = $this->gmailPubSubEnvelope('msg-x', ['emailAddress' => 'user@firm.test']);

        $parsed = $this->provider()->parseInboundEvent($body, []);

        $this->assertNull($parsed['event_id']);
        $this->assertNull($parsed['event_type']);
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function gmailPubSubEnvelope(string $messageId, array $data): string
    {
        $encodedData = rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return json_encode([
            'message' => [
                'messageId' => $messageId,
                'data' => $encodedData,
            ],
            'subscription' => 'projects/unit-test/subscriptions/gmail-push',
        ], JSON_THROW_ON_ERROR);
    }

    private function invokeListenerMapping(?string $eventType): ?ResourceType
    {
        $job = new DispatchPullSyncOnVerifiedWebhookEvent(1, 1, ProviderKey::GoogleWorkspace->value, $eventType, 1);

        $method = new ReflectionMethod($job, 'mapEventTypeToResourceType');
        $method->setAccessible(true);

        return $method->invoke($job, $eventType);
    }
}
