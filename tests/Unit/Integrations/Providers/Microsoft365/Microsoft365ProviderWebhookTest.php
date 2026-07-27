<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Providers\Microsoft365;

use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Listeners\DispatchPullSyncOnVerifiedWebhookEvent;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Providers\Microsoft365\Microsoft365Provider;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Microsoft365ProviderWebhookTest — FirmsVault Live Integrations,
 * Checkpoint 2 (test-writer pass). Unit-level coverage of
 * Microsoft365Provider's webhook-shaped methods
 * (detectSubscriptionValidationChallenge()/extractRoutingIdentifier()/
 * verifyInboundSignature()/parseInboundEvent()) plus pull()'s Graph
 * `/delta` walk semantics.
 *
 * THE REGRESSION TEST FOR THE FIXED DEFECT (see
 * checkpoint2-diff-review.md §7): parseInboundEvent() originally derived
 * `event_type` purely from Graph's `changeType` (e.g. "created"), which
 * could never satisfy
 * App\Integrations\Listeners\DispatchPullSyncOnVerifiedWebhookEvent::mapEventTypeToResourceType()'s
 * documented contract (event_type must exactly match, or lead with, a
 * ResourceType value) — meaning webhook-triggered sync could never fire
 * for a real Microsoft delivery. The fix adds a private
 * resourceTypeSegmentFor() helper that derives a resource-type prefix
 * from each item's `resource` field, producing event_type values like
 * "message:created". test_parse_inbound_event_produces_a_resource_type_prefixed_event_type_the_listener_can_map()
 * below proves the two classes' contract agrees END TO END — not merely
 * that each class independently does something plausible — by feeding
 * parseInboundEvent()'s own real output into the listener's own real
 * (reflected) mapEventTypeToResourceType() method.
 */
final class Microsoft365ProviderWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function provider(): Microsoft365Provider
    {
        return app(Microsoft365Provider::class);
    }

    // ------------------------------------------------------------
    // detectSubscriptionValidationChallenge()
    // ------------------------------------------------------------

    public function test_detect_subscription_validation_challenge_echoes_the_token_byte_for_byte(): void
    {
        $result = $this->provider()->detectSubscriptionValidationChallenge(
            ['validationToken' => 'this-is-the-raw-token-value=&weird+chars'],
            [],
        );

        $this->assertSame([
            'body' => 'this-is-the-raw-token-value=&weird+chars',
            'status' => 200,
            'content_type' => 'text/plain',
        ], $result);
    }

    public function test_detect_subscription_validation_challenge_returns_null_when_absent(): void
    {
        $this->assertNull($this->provider()->detectSubscriptionValidationChallenge([], []));
        $this->assertNull($this->provider()->detectSubscriptionValidationChallenge(['other_param' => 'x'], []));
    }

    public function test_detect_subscription_validation_challenge_returns_null_for_an_empty_string_token(): void
    {
        $this->assertNull($this->provider()->detectSubscriptionValidationChallenge(['validationToken' => ''], []));
    }

    /**
     * Never throws even on a garbage $queryParams array — non-string
     * validationToken values (array/int/null/nested) must all resolve
     * to a clean null, never an uncaught TypeError/warning.
     */
    public function test_detect_subscription_validation_challenge_never_throws_on_garbage_query_params(): void
    {
        $garbageShapes = [
            ['validationToken' => ['nested' => ['deeply' => 'array']]],
            ['validationToken' => 12345],
            ['validationToken' => null],
            ['validationToken' => false],
            ['validationToken' => new \stdClass],
            ['foo' => ['bar' => ['baz' => 'qux']], 'validationToken' => [1, 2, 3]],
        ];

        foreach ($garbageShapes as $shape) {
            $result = $this->provider()->detectSubscriptionValidationChallenge($shape, []);
            $this->assertNull($result, 'A non-string validationToken must resolve to null, never throw.');
        }
    }

    // ------------------------------------------------------------
    // extractRoutingIdentifier()
    // ------------------------------------------------------------

    public function test_extract_routing_identifier_returns_the_shared_client_state_when_all_items_agree(): void
    {
        $body = json_encode([
            'value' => [
                ['clientState' => 'shared-token-abc', 'resource' => 'me/contacts', 'changeType' => 'created'],
                ['clientState' => 'shared-token-abc', 'resource' => 'me/events', 'changeType' => 'updated'],
            ],
        ]);

        $result = $this->provider()->extractRoutingIdentifier($body, []);

        $this->assertSame('shared-token-abc', $result);
    }

    public function test_extract_routing_identifier_fails_closed_when_items_disagree(): void
    {
        $body = json_encode([
            'value' => [
                ['clientState' => 'token-a', 'resource' => 'me/contacts', 'changeType' => 'created'],
                ['clientState' => 'token-b', 'resource' => 'me/events', 'changeType' => 'updated'],
            ],
        ]);

        $this->assertNull($this->provider()->extractRoutingIdentifier($body, []));
    }

    public function test_extract_routing_identifier_fails_closed_when_any_item_is_missing_client_state(): void
    {
        $body = json_encode([
            'value' => [
                ['clientState' => 'token-a', 'resource' => 'me/contacts', 'changeType' => 'created'],
                ['resource' => 'me/events', 'changeType' => 'updated'],
            ],
        ]);

        $this->assertNull($this->provider()->extractRoutingIdentifier($body, []));
    }

    public function test_extract_routing_identifier_fails_closed_when_client_state_is_empty_string(): void
    {
        $body = json_encode([
            'value' => [
                ['clientState' => '', 'resource' => 'me/contacts', 'changeType' => 'created'],
            ],
        ]);

        $this->assertNull($this->provider()->extractRoutingIdentifier($body, []));
    }

    public function test_extract_routing_identifier_never_throws_on_malformed_json(): void
    {
        $this->assertNull($this->provider()->extractRoutingIdentifier('{not valid json at all', []));
        $this->assertNull($this->provider()->extractRoutingIdentifier('', []));
        $this->assertNull($this->provider()->extractRoutingIdentifier('null', []));
        $this->assertNull($this->provider()->extractRoutingIdentifier('"just a string"', []));
        $this->assertNull($this->provider()->extractRoutingIdentifier('{"value": "not-an-array"}', []));
    }

    // ------------------------------------------------------------
    // verifyInboundSignature()
    // ------------------------------------------------------------

    public function test_verify_inbound_signature_always_returns_true(): void
    {
        $provider = $this->provider();

        $this->assertTrue($provider->verifyInboundSignature('{}', []));
        $this->assertTrue($provider->verifyInboundSignature('', []));
        $this->assertTrue($provider->verifyInboundSignature('garbage-not-json', ['X-Some-Header' => 'value']));
        $this->assertTrue($provider->verifyInboundSignature('{"value":[]}', ['anything' => 'at-all']));
    }

    // ------------------------------------------------------------
    // parseInboundEvent() — THE REGRESSION TEST FOR THE FIXED DEFECT
    // ------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function pullableResourceProvider(): array
    {
        return [
            'message' => [
                "me/mailFolders('Inbox')/messages",
                'created',
                'message:created',
                ResourceType::Message->value,
            ],
            'calendar event' => [
                'me/events',
                'updated',
                'calendar_event:updated',
                ResourceType::CalendarEvent->value,
            ],
            'contact' => [
                'me/contacts',
                'deleted',
                'contact:deleted',
                ResourceType::Contact->value,
            ],
        ];
    }

    #[DataProvider('pullableResourceProvider')]
    public function test_parse_inbound_event_produces_a_resource_type_prefixed_event_type_the_listener_can_map(
        string $graphResource,
        string $changeType,
        string $expectedEventType,
        string $expectedResourceType,
    ): void {
        $body = json_encode([
            'value' => [
                [
                    'subscriptionId' => 'sub-123',
                    'resource' => $graphResource.'/AAMkAII-item-1',
                    'changeType' => $changeType,
                    'clientState' => 'routing-token',
                    'resourceData' => ['id' => 'AAMkAII-item-1'],
                ],
            ],
        ]);

        $parsed = $this->provider()->parseInboundEvent($body, []);

        $this->assertNotNull($parsed['event_id']);
        $this->assertSame($expectedEventType, $parsed['event_type']);
        $this->assertSame(['value' => json_decode($body, true)['value']], $parsed['payload']);

        // Now feed that EXACT event_type string into the listener's own
        // real mapEventTypeToResourceType() — proving the two classes'
        // contract agrees end-to-end, not just that each independently
        // does something plausible.
        $resolved = $this->invokeListenerMapping($parsed['event_type']);

        $this->assertNotNull($resolved, "event_type \"{$parsed['event_type']}\" must map to a ResourceType — this is the regression this test exists to prevent.");
        $this->assertSame($expectedResourceType, $resolved->value);
    }

    public function test_parse_inbound_event_is_deterministic_for_identical_content_true_redelivery(): void
    {
        $body = json_encode([
            'value' => [
                [
                    'subscriptionId' => 'sub-123',
                    'resource' => 'me/contacts/AAMk-1',
                    'changeType' => 'created',
                    'clientState' => 'routing-token',
                    'resourceData' => ['id' => 'AAMk-1'],
                ],
            ],
        ]);

        $first = $this->provider()->parseInboundEvent($body, []);
        $second = $this->provider()->parseInboundEvent($body, []);

        $this->assertSame($first['event_id'], $second['event_id'], 'Identical value[] content (a true redelivery) must produce an identical event_id.');
    }

    public function test_parse_inbound_event_with_an_unrecognized_resource_leaves_event_type_unprefixed_and_the_listener_correctly_skips_it(): void
    {
        $body = json_encode([
            'value' => [
                [
                    'subscriptionId' => 'sub-999',
                    'resource' => 'me/somethingCompletelyUnrelated/AAMk-9',
                    'changeType' => 'created',
                    'clientState' => 'routing-token',
                    'resourceData' => ['id' => 'AAMk-9'],
                ],
            ],
        ]);

        $parsed = $this->provider()->parseInboundEvent($body, []);

        $this->assertSame('created', $parsed['event_type'], 'An unrecognized resource must leave event_type unprefixed (bare changeType).');

        $resolved = $this->invokeListenerMapping($parsed['event_type']);

        $this->assertNull($resolved, 'The fail-safe "skip rather than guess" behavior must still hold for an unmapped, unprefixed event_type.');
    }

    public function test_parse_inbound_event_lifecycle_notification_produces_a_lifecycle_prefix_untouched(): void
    {
        $body = json_encode([
            'value' => [
                [
                    'subscriptionId' => 'sub-555',
                    'lifecycleEvent' => 'reauthorizationRequired',
                    'resourceData' => ['id' => 'irrelevant'],
                ],
            ],
        ]);

        $parsed = $this->provider()->parseInboundEvent($body, []);

        $this->assertSame('lifecycle:reauthorizationRequired', $parsed['event_type']);

        // Confirmed the listener also correctly does NOT dispatch for a
        // lifecycle notification — "lifecycle" is not a ResourceType
        // value.
        $resolved = $this->invokeListenerMapping($parsed['event_type']);
        $this->assertNull($resolved);
    }

    public function test_parse_inbound_event_returns_the_documented_shape_for_malformed_or_empty_value(): void
    {
        $expected = ['event_id' => null, 'event_type' => null, 'payload' => []];

        $this->assertSame($expected, $this->provider()->parseInboundEvent('{"value": []}', []));
        $this->assertSame($expected, $this->provider()->parseInboundEvent('{"no_value_key": true}', []));
        $this->assertSame($expected, $this->provider()->parseInboundEvent('not valid json', []));
        $this->assertSame($expected, $this->provider()->parseInboundEvent('', []));
        $this->assertSame($expected, $this->provider()->parseInboundEvent('{"value": "not-an-array"}', []));
    }

    public function test_parse_inbound_event_combines_multiple_items_into_a_sorted_unique_comma_joined_event_type(): void
    {
        $body = json_encode([
            'value' => [
                ['subscriptionId' => 's1', 'resource' => 'me/contacts/1', 'changeType' => 'created', 'resourceData' => ['id' => '1']],
                ['subscriptionId' => 's1', 'resource' => 'me/events/2', 'changeType' => 'updated', 'resourceData' => ['id' => '2']],
                // A duplicate-shaped changeType (same resulting compound
                // string) must not appear twice.
                ['subscriptionId' => 's1', 'resource' => 'me/contacts/3', 'changeType' => 'created', 'resourceData' => ['id' => '3']],
            ],
        ]);

        $parsed = $this->provider()->parseInboundEvent($body, []);

        $this->assertSame('calendar_event:updated,contact:created', $parsed['event_type']);
    }

    /**
     * Invokes DispatchPullSyncOnVerifiedWebhookEvent's private
     * mapEventTypeToResourceType() via reflection — the cleanest
     * available pattern since the method is intentionally private (no
     * production caller needs it to be public) and this class has no
     * queue/job-dispatch side effects worth avoiding for a pure mapping
     * check.
     */
    private function invokeListenerMapping(?string $eventType): ?ResourceType
    {
        $job = new DispatchPullSyncOnVerifiedWebhookEvent(1, 1, ProviderKey::Microsoft365->value, $eventType, 1);

        $method = new ReflectionMethod($job, 'mapEventTypeToResourceType');
        $method->setAccessible(true);

        return $method->invoke($job, $eventType);
    }

    // ------------------------------------------------------------
    // pull() — Graph /delta walk semantics
    // ------------------------------------------------------------

    private function microsoft365ConnectionWithCredential(Firm $firm): FirmIntegration
    {
        $providerRow = IntegrationProvider::query()->where('code', ProviderKey::Microsoft365->value)->firstOrFail();

        $connection = $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()->forFirm($firm)->forProvider($providerRow)->create(['external_account_id' => null]),
        );

        $this->runWithFirmContext(
            $firm,
            fn () => IntegrationCredential::factory()->forFirmIntegration($connection)->ofType(CredentialType::OauthAccessToken)->create(),
        );

        return $connection;
    }

    public function test_pull_returns_has_more_true_and_the_full_next_link_url_for_a_mid_walk_page(): void
    {
        Http::fake([
            'https://graph.microsoft.com/v1.0/me/contacts/delta' => Http::response([
                'value' => [
                    ['id' => 'contact-1', '@odata.etag' => 'W/"abc123"'],
                ],
                '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/contacts/delta?$skiptoken=abc123',
            ], 200),
        ]);

        $firm = Firm::factory()->create();
        $connection = $this->microsoft365ConnectionWithCredential($firm);

        $result = $this->runWithFirmContext(
            $firm,
            fn () => $this->provider()->pull(['connection' => $connection], ResourceType::Contact->value, null),
        );

        $this->assertTrue($result['has_more']);
        $this->assertSame('https://graph.microsoft.com/v1.0/me/contacts/delta?$skiptoken=abc123', $result['next_cursor']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('contact-1', $result['items'][0]['external_id']);
    }

    public function test_pull_returns_has_more_false_and_the_full_delta_link_url_for_the_terminal_page(): void
    {
        Http::fake([
            'https://graph.microsoft.com/v1.0/me/contacts/delta' => Http::response([
                'value' => [],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/contacts/delta?$deltatoken=xyz789',
            ], 200),
        ]);

        $firm = Firm::factory()->create();
        $connection = $this->microsoft365ConnectionWithCredential($firm);

        $result = $this->runWithFirmContext(
            $firm,
            fn () => $this->provider()->pull(['connection' => $connection], ResourceType::Contact->value, null),
        );

        $this->assertFalse($result['has_more']);
        $this->assertSame('https://graph.microsoft.com/v1.0/me/contacts/delta?$deltatoken=xyz789', $result['next_cursor']);
    }

    public function test_pull_hits_the_configured_graph_base_url_not_the_identity_one(): void
    {
        Http::fake([
            'https://graph.microsoft.com/*' => Http::response([
                'value' => [],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/events/delta?$deltatoken=z',
            ], 200),
            'https://login.microsoftonline.com/*' => Http::response(['error' => 'must never be called by pull()'], 500),
        ]);

        $firm = Firm::factory()->create();
        $connection = $this->microsoft365ConnectionWithCredential($firm);

        $this->runWithFirmContext(
            $firm,
            fn () => $this->provider()->pull(['connection' => $connection], ResourceType::CalendarEvent->value, null),
        );

        Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://graph.microsoft.com/'));
        Http::assertNotSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://login.microsoftonline.com/'));
    }

    /**
     * PRODUCTION BUG FOUND BY THIS TEST, NOT FIXED HERE (per task
     * instructions — test-writer scope only): as of this writing, this
     * test FAILS. Root cause, confirmed via a standalone Guzzle
     * MockHandler reproduction AND via Http::recorded() against a real
     * Microsoft365Provider::pull() call:
     * App\Integrations\Support\ProviderRequestExecutor::send() builds its
     * GET-request options as `['query' => $body]` unconditionally (see
     * that method's `$options = match(true) { $httpMethod === 'GET' =>
     * ['query' => $body], ... }` line). Microsoft365Provider::pull()
     * never passes a `$body`, so this is always `['query' => []]`. Even
     * though Guzzle's `query` option is an EMPTY array, passing it at all
     * causes Guzzle's `Utils::modifyRequest()` to unconditionally REPLACE
     * the target URL's existing query string with the (empty)
     * http_build_query() result — silently STRIPPING any query string
     * already embedded in the URL before the request is ever sent. This
     * defeats the entire "store the full opaque nextLink/deltaLink URL as
     * cursor_value, pass it back verbatim as pull()'s $url" design this
     * checkpoint documents (SupportsPullSyncContract::pull()'s own
     * docblock, and Microsoft365Provider::pull()'s docblock) for every
     * page after the first: Microsoft Graph's `$skiptoken=...`/
     * `$deltatoken=...` query parameter is silently dropped, so every
     * "next page" or "incremental resync" request actually re-requests
     * the bare, unparameterized initial delta URL — pagination does not
     * advance, and incremental sync silently performs a full resync on
     * every call instead of a true delta continuation. Reproduction (run
     * against this exact call shape, independent of this test file):
     * `(new \GuzzleHttp\Client(['handler' => $mockStack]))->request('GET',
     * 'https://graph.microsoft.com/v1.0/me/contacts/delta?$skiptoken=continue-here',
     * ['query' => []])` sends a request whose URI is
     * `https://graph.microsoft.com/v1.0/me/contacts/delta` — query string
     * gone. Likely fix (not implemented here, out of this task's scope):
     * `ProviderRequestExecutor::send()`'s GET branch should omit the
     * `query` option entirely (or merge into the URL's existing query)
     * when `$body === []`, rather than always passing `['query' =>
     * $body]`. This test is left asserting the INTENDED, documented
     * behavior (not the current buggy behavior) so it will start passing
     * automatically once the underlying executor bug is fixed, and so it
     * does not silently mask the defect.
     */
    public function test_pull_with_a_non_null_cursor_requests_the_exact_opaque_link_url_verbatim(): void
    {
        $nextLink = 'https://graph.microsoft.com/v1.0/me/contacts/delta?$skiptoken=continue-here';

        Http::fake([
            'https://graph.microsoft.com/v1.0/me/contacts/delta' => Http::response([
                'value' => [],
                '@odata.nextLink' => $nextLink,
            ], 200),
            $nextLink => Http::response([
                'value' => [['id' => 'contact-2', '@odata.etag' => 'W/"def"']],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/contacts/delta?$deltatoken=final',
            ], 200),
        ]);

        $firm = Firm::factory()->create();
        $connection = $this->microsoft365ConnectionWithCredential($firm);

        $firstPage = $this->runWithFirmContext(
            $firm,
            fn () => $this->provider()->pull(['connection' => $connection], ResourceType::Contact->value, null),
        );

        $this->assertSame($nextLink, $firstPage['next_cursor']);

        $secondPage = $this->runWithFirmContext(
            $firm,
            fn () => $this->provider()->pull(['connection' => $connection], ResourceType::Contact->value, $firstPage['next_cursor']),
        );

        $this->assertFalse($secondPage['has_more']);
        $this->assertCount(1, $secondPage['items']);
        $this->assertSame('contact-2', $secondPage['items'][0]['external_id']);

        Http::assertSent(fn ($request): bool => (string) $request->url() === $nextLink);
    }
}
