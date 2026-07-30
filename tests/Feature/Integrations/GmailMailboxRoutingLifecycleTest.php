<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Integrations\Data\OAuthCallbackResult;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Exceptions\GmailMailboxAlreadyRoutedException;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Providers\GoogleWorkspace\GoogleWorkspaceProvider;
use App\Integrations\Services\ProviderConnectionService;
use App\Integrations\Support\GmailMailboxRoutingService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TenantEncryptionKey;
use App\Models\User;
use App\Services\EmailBodyEncryptionService;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;
use Throwable;

/**
 * GmailMailboxRoutingLifecycleTest — Checkpoint 3 (FirmsVault Live
 * Integrations, Google Workspace provider) test-writing pass.
 *
 * Proves route()/unroute() are correctly wired into the REAL
 * `ProviderConnectionService::disconnect()`/`disableWebhookRouting()`
 * call sites (both now call `$this->gmailMailboxRouting->unroute($fresh)`)
 * and into the new `bootstrapWebhookSubscriptions()` orchestration
 * `finishCallback()` calls alongside `enableWebhookRouting()` — per
 * checkpoint3-design-sync-webhooks.md §6.5 and
 * checkpoint3-combined-design.md §4.7/§10.4's exact scenario matrix
 * (Finding 1/Finding 6 of checkpoint3-security-review.md).
 *
 * Every network-shaped call goes through Http::fake([...]) — mandatory
 * given tests/TestCase.php's suite-wide Http::preventStrayRequests()
 * guard. No real Google credentials/endpoints are ever used; the
 * token/gmail base URLs and OAuth client id/secret are all
 * config()-overridden test fixtures. `Google\Auth\AccessToken` is never
 * exercised by any test in this file (no Gmail webhook JWT is ever
 * verified here) — the real container-bound singleton is used
 * unmodified, since its constructor performs no network I/O of its own
 * (only ::verify() does).
 */
final class GmailMailboxRoutingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'unit-test-gw-client-id-0001';

    private const CLIENT_SECRET = 'unit-test-gw-client-secret-0001';

    private const TOKEN_BASE = 'https://oauth2.googleapis.test';

    private const GMAIL_BASE = 'https://gmail.googleapis.test';

    private const CALENDAR_BASE = 'https://calendar.googleapis.test';

    private const DRIVE_BASE = 'https://drive.googleapis.test';

    private const HMAC_KEY = 'unit-test-gmail-mailbox-routing-hmac-key-fixture-0001';

    private const REQUIRED_SCOPE = 'openid email https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/gmail.send';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://app.firmsbase.test']);
        URL::forceRootUrl('https://app.firmsbase.test');
        URL::forceScheme('https');

        config(['integrations.providers' => [ProviderKey::GoogleWorkspace->value => GoogleWorkspaceProvider::class]]);

        config([
            'integrations.oauth_apps.googleworkspace.client_id' => self::CLIENT_ID,
            'integrations.oauth_apps.googleworkspace.client_secret' => self::CLIENT_SECRET,
            'integrations.oauth_apps.googleworkspace.gmail_mailbox_routing_hmac_key' => self::HMAC_KEY,
            'integrations.provider_environments.'.ProviderKey::GoogleWorkspace->value => [
                'mode' => 'sandbox',
                'sandbox_base_urls' => [
                    'token' => self::TOKEN_BASE,
                    'gmail' => self::GMAIL_BASE,
                    'calendar' => self::CALENDAR_BASE,
                    'drive' => self::DRIVE_BASE,
                ],
                'live_base_urls' => [
                    'token' => self::TOKEN_BASE,
                    'gmail' => self::GMAIL_BASE,
                    'calendar' => self::CALENDAR_BASE,
                    'drive' => self::DRIVE_BASE,
                ],
            ],
        ]);
    }

    // ------------------------------------------------------------
    // 1. Connects — bootstrapWebhookSubscriptions() creates the route
    // ------------------------------------------------------------

    public function test_a_first_time_oauth_connect_creates_the_gmail_mailbox_route_via_bootstrap_webhook_subscriptions(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();

        $this->fakeSuccessfulGmailConnect('firm-mailbox@example.test');

        $result = $this->connect($connection, $firmUser);

        $this->assertTrue($result->successful);
        $this->assertSame(ConnectionStatus::Active, $result->status);

        $subscription = $this->runWithFirmContext(
            $firm,
            fn () => DB::table('integration_provider_webhook_subscriptions')
                ->where('firm_integration_id', $connection->id)
                ->first()
        );

        $this->assertNotNull($subscription, 'bootstrapWebhookSubscriptions() must persist a subscription row on first connect.');
        $this->assertSame('googleworkspace', $subscription->provider_key);
        $this->assertSame(ResourceType::Message->value, $subscription->resource_type);
        $this->assertSame('active', $subscription->status);

        $route = DB::table('integration_gmail_mailbox_routes')->where('firm_integration_id', $connection->id)->first();
        $this->assertNotNull($route, 'GoogleWorkspaceProvider::subscribe() must call GmailMailboxRoutingService::route() from inside bootstrapWebhookSubscriptions().');
        $this->assertSame($firm->id, $route->firm_id);
    }

    // ------------------------------------------------------------
    // 2. Ambiguous mailbox — whole callback rolls back, never a silent
    //    degrade (Finding 1 / Finding 6)
    // ------------------------------------------------------------

    public function test_connecting_an_already_routed_mailbox_rolls_back_the_entire_oauth_callback_rather_than_degrading_silently(): void
    {
        [$firm, $connectionA, $firmUserA] = $this->firmConnectionAndActor();
        $this->fakeSuccessfulGmailConnect('shared-mailbox@example.test');
        $resultA = $this->connect($connectionA, $firmUserA);
        $this->assertTrue($resultA->successful);

        // A second, different connection under the SAME firm (e.g. a
        // second staff member authorizing against the same shared
        // mailbox) attempts to route the identical mailbox address.
        $connectionB = $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()
                ->forFirm($firm)
                ->forProvider($this->googleWorkspaceProviderRow())
                ->pending()
                ->create(['external_account_id' => null, 'requested_capabilities_json' => [ResourceType::Message->value]])
        );
        $firmUserB = $this->firmUserFor($firm, FirmUserRole::Attorney);

        // A DIFFERENT Google account ('sub') from connection A's — only
        // the reported Gmail mailbox address collides. Using the SAME
        // 'sub' here would instead trip firm_integrations' OWN
        // (firm_id, integration_provider_id, external_account_id)
        // partial unique index first (both connections share this
        // firm), which would prove the wrong thing entirely — this test
        // is specifically about GmailMailboxRoutingService::route()'s
        // mailbox_lookup_hmac collision, not account-identity collision.
        $this->fakeSuccessfulGmailConnect('shared-mailbox@example.test', [], 'a-different-google-account');

        $threw = false;

        try {
            $this->connect($connectionB, $firmUserB);
        } catch (Throwable $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'A colliding mailbox route must propagate as a real exception, rolling back the whole OAuth callback.');

        $freshB = $this->runWithFirmContext($firm, fn () => FirmIntegration::query()->where('id', $connectionB->id)->first());
        $this->assertSame(ConnectionStatus::Pending, $freshB->status, 'The whole transaction — including the earlier status transition to Active — must roll back, leaving connection B exactly where it started.');

        $subscriptionCountB = $this->runWithFirmContext(
            $firm,
            fn () => DB::table('integration_provider_webhook_subscriptions')->where('firm_integration_id', $connectionB->id)->count()
        );
        $this->assertSame(0, $subscriptionCountB, 'No partial subscription row may survive the rollback.');

        $routes = DB::table('integration_gmail_mailbox_routes')->get();
        $this->assertCount(1, $routes, 'The mailbox must still resolve to exactly connection A — no duplicate, no orphaned row.');
        $this->assertSame($connectionA->id, (int) $routes->first()->firm_integration_id);
    }

    // ------------------------------------------------------------
    // 3. Disconnects
    // ------------------------------------------------------------

    public function test_disconnect_removes_the_gmail_mailbox_route_in_the_same_transaction_as_the_status_transition(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->fakeSuccessfulGmailConnect('to-be-disconnected@example.test');
        $this->connect($connection, $firmUser);

        $this->assertSame(1, DB::table('integration_gmail_mailbox_routes')->where('firm_integration_id', $connection->id)->count());

        $fresh = $this->runWithFirmContext(
            $firm,
            fn () => app(ProviderConnectionService::class)->disconnect($connection, $firmUser->user_id)
        );

        $this->assertSame(ConnectionStatus::Disconnected, $fresh->status);
        $this->assertSame(0, DB::table('integration_gmail_mailbox_routes')->where('firm_integration_id', $connection->id)->count(), 'disconnect() must call GmailMailboxRoutingService::unroute() so no stale route survives.');
    }

    public function test_disable_webhook_routing_removes_the_gmail_mailbox_route(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->fakeSuccessfulGmailConnect('routing-disabled@example.test');
        $this->connect($connection, $firmUser);

        $this->assertSame(1, DB::table('integration_gmail_mailbox_routes')->where('firm_integration_id', $connection->id)->count());

        $this->runWithFirmContext(
            $firm,
            fn () => app(ProviderConnectionService::class)->disableWebhookRouting($connection, $firmUser->user_id)
        );

        $fresh = $this->runWithFirmContext($firm, fn () => FirmIntegration::query()->where('id', $connection->id)->first());
        $this->assertSame(ConnectionStatus::Active, $fresh->status, 'disableWebhookRouting() must not change the connection status.');
        $this->assertNull($fresh->webhook_routing_token);
        $this->assertSame(0, DB::table('integration_gmail_mailbox_routes')->where('firm_integration_id', $connection->id)->count(), 'disableWebhookRouting() must call GmailMailboxRoutingService::unroute() so no stale route survives.');
    }

    // ------------------------------------------------------------
    // 4. Reconnect after full disconnect — replace, never duplicate
    // ------------------------------------------------------------

    public function test_reconnecting_the_same_mailbox_after_a_full_disconnect_replaces_rather_than_duplicates_the_route(): void
    {
        [$firm, $connectionA, $firmUserA] = $this->firmConnectionAndActor();
        $this->fakeSuccessfulGmailConnect('reconnecting-mailbox@example.test');
        $this->connect($connectionA, $firmUserA);

        $this->runWithFirmContext(
            $firm,
            fn () => app(ProviderConnectionService::class)->disconnect($connectionA, $firmUserA->user_id)
        );
        $this->assertSame(0, DB::table('integration_gmail_mailbox_routes')->count());

        // A true reconnect after a full disconnect always creates a
        // brand-new FirmIntegration row (finishCallback() unconditionally
        // rejects completing OAuth against an already-Disconnected row).
        $connectionB = $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()
                ->forFirm($firm)
                ->forProvider($this->googleWorkspaceProviderRow())
                ->pending()
                ->create(['external_account_id' => null, 'requested_capabilities_json' => [ResourceType::Message->value]])
        );
        $firmUserB = $this->firmUserFor($firm, FirmUserRole::Attorney);

        $this->fakeSuccessfulGmailConnect('reconnecting-mailbox@example.test');
        $resultB = $this->connect($connectionB, $firmUserB);

        $this->assertTrue($resultB->successful);

        $routes = DB::table('integration_gmail_mailbox_routes')->get();
        $this->assertCount(1, $routes, 'Exactly one route must exist after the reconnect — never a duplicate.');
        $this->assertSame($connectionB->id, (int) $routes->first()->firm_integration_id);
    }

    // ------------------------------------------------------------
    // 5. Cross-firm ambiguous mailbox
    // ------------------------------------------------------------

    /**
     * CHECKPOINT 8.2 (§A7b) REWRITE. The security contract this test
     * enforces is unchanged and its assertions are strictly stronger; only
     * the MECHANISM it relied on changed.
     *
     * It used to assert that a cross-firm mailbox conflict "rolls back the
     * whole transaction, leaving firm 2's connection exactly where it
     * started". That worked because the route was claimed AFTER the Gmail
     * watch() call, inside the OAuth transaction — so the only way to undo
     * a conflict was to discard a completed authorization, using a rollback
     * to compensate for a provider call that had already been made.
     *
     * The claim now runs BEFORE any network call, as one short
     * autocommitted statement, so a conflict is caught with zero provider
     * calls made and nothing to compensate for. This test therefore no
     * longer depends on outer-transaction rollback for anything: its
     * fixtures are committed, and it cleans up explicitly through the
     * owning connection.
     */
    public function test_a_second_firms_connection_cannot_claim_a_gmail_mailbox_already_owned_by_another_firm(): void
    {
        // (1) Committed fixtures — two firms, two connections, real for
        // every database session rather than only this test's transaction.
        [$firmA, $connectionA] = $this->committedFirmAndConnection();
        [$firmB, $connectionB] = $this->committedFirmAndConnection();

        $routing = app(GmailMailboxRoutingService::class);
        $mailbox = 'cross-firm-owned-mailbox@example.test';

        // Firm A legitimately claims the mailbox.
        $this->withCommittedFirmContext($firmA, fn () => $routing->route($connectionA, $mailbox));

        $ownerBefore = $this->committedRouteForConnection($connectionA->id);
        $this->assertNotNull($ownerBefore);

        // (2)+(3) Firm B attempts the SAME mailbox. The globally-unique
        // constraint must fail it closed.
        $threw = null;

        try {
            $this->withCommittedFirmContext($firmB, fn () => $routing->route($connectionB, $mailbox));
        } catch (Throwable $e) {
            $threw = $e;
        }

        $this->assertInstanceOf(
            GmailMailboxAlreadyRoutedException::class,
            $threw,
            'A second firm must never be able to claim a mailbox already routed to another firm.'
        );
        $this->assertSame((int) $connectionB->id, $threw->requestedFirmIntegrationId);
        $this->assertSame((int) $connectionA->id, $threw->owningFirmIntegrationId);

        // (4)+(5) The existing owner is untouched: not reassigned, not
        // duplicated, not deleted.
        $routes = $this->committedRoutesForConnections([(int) $connectionA->id, (int) $connectionB->id]);

        $this->assertCount(1, $routes, 'No duplicate route may be created by the refused claim.');
        $this->assertSame((int) $connectionA->id, (int) $routes[0]->firm_integration_id, 'Ownership must not be reassigned.');
        $this->assertSame((int) $firmA->id, (int) $routes[0]->firm_id);
        $this->assertSame($ownerBefore->mailbox_lookup_hmac, $routes[0]->mailbox_lookup_hmac);
        $this->assertSame(
            $ownerBefore->mailbox_display_ciphertext,
            $routes[0]->mailbox_display_ciphertext,
            'The existing owner\'s stored mailbox value must be byte-identical afterwards.'
        );
        $this->assertSame(
            0,
            $this->committedRouteCountFor((int) $connectionB->id),
            'The refused connection must hold no route at all.'
        );

        // (8) Explicit cleanup through the OWNING connection — never a
        // reliance on transaction rollback.
        $this->withCommittedFirmContext($firmA, fn () => $routing->unroute($connectionA));
        $this->assertSame(0, $this->committedRouteCountFor((int) $connectionA->id));
    }

    /**
     * (6)+(7) The other half of the same contract: when the local claim is
     * refused, NO provider network call is made and nothing about the
     * refused connection is corrupted.
     *
     * This is what makes "claim before call" worth doing. Under the old
     * ordering the watch() request had already been sent by the time the
     * conflict surfaced, so a real Gmail subscription existed at Google
     * that nothing local recorded.
     */
    public function test_a_refused_mailbox_claim_makes_no_provider_call_and_corrupts_no_connection_state(): void
    {
        [$firmA, $connectionA] = $this->committedFirmAndConnection();
        [$firmB, $connectionB] = $this->committedFirmAndConnection();

        $routing = app(GmailMailboxRoutingService::class);
        $mailbox = 'no-call-on-refusal@example.test';

        $this->withCommittedFirmContext($firmA, fn () => $routing->route($connectionA, $mailbox));

        // Any outbound HTTP at all would show up here — and TestCase's own
        // preventStrayRequests() already forbids un-faked requests.
        Http::fake();

        $before = $this->committedConnectionRow($firmB, (int) $connectionB->id);

        try {
            $this->withCommittedFirmContext($firmB, fn () => $routing->route($connectionB, $mailbox));
            $this->fail('The claim must have been refused.');
        } catch (GmailMailboxAlreadyRoutedException) {
            // expected
        }

        Http::assertNothingSent();

        // (7) Firm B's connection is untouched.
        $after = $this->committedConnectionRow($firmB, (int) $connectionB->id);

        $this->assertSame($before->status, $after->status, 'A refused claim must not change connection status.');
        $this->assertSame($before->webhook_routing_token, $after->webhook_routing_token);
        $this->assertSame($before->external_account_id, $after->external_account_id);
        $this->assertSame(
            0,
            $this->committedCredentialCountFor($firmB, (int) $connectionB->id),
            'No credential may be created or destroyed by a refused mailbox claim.'
        );

        // Explicit cleanup via the owning connection.
        $this->withCommittedFirmContext($firmA, fn () => $routing->unroute($connectionA));
    }

    // ------------------------------------------------------------
    // 6. Route provenance — authenticated profile, never the unverified
    //    inbound webhook payload
    // ------------------------------------------------------------

    public function test_route_is_derived_from_the_authenticated_gmail_profile_response_never_from_the_unverified_inbound_webhook_email_address(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();

        $profileEmail = 'authenticated-profile-owner@example.test';
        // A decoy value planted in the ID token's own (unused-by-this-
        // provider) 'email' claim — GoogleWorkspaceProvider never reads
        // claims['email'] anywhere (only sub/hd), and there is no
        // inbound webhook delivery anywhere in this test's flow. If the
        // route were ever derived from anything other than the
        // authenticated users.getProfile() response, this decoy value
        // would leak into the persisted route instead.
        $decoyEmail = 'decoy-should-never-be-routed@attacker.test';

        $this->fakeSuccessfulGmailConnect($profileEmail, ['email' => $decoyEmail, 'email_verified' => true]);

        $result = $this->connect($connection, $firmUser);
        $this->assertTrue($result->successful);

        $route = DB::table('integration_gmail_mailbox_routes')->where('firm_integration_id', $connection->id)->first();
        $this->assertNotNull($route);

        $decrypted = app(EmailBodyEncryptionService::class)->decrypt($firm, $route->mailbox_display_ciphertext, (int) $route->mailbox_display_encryption_key_id);

        $this->assertSame($profileEmail, $decrypted, 'The persisted route must reflect the authenticated users.getProfile() response.');
        $this->assertNotSame($decoyEmail, $decrypted, 'The persisted route must never reflect an unverified/decoy value from anywhere else in the flow.');
    }

    // ------------------------------------------------------------
    // 7. Atomicity — a failed subscribe() leaves no partial route row
    // ------------------------------------------------------------

    /**
     * CHECKPOINT 8.2 (§A7b) REWRITE. This previously asserted that a
     * malformed watch() response rolled back "the ENTIRE ambient
     * transaction — including the route() insert that already happened".
     *
     * That is deliberately no longer the contract. The route is claimed
     * BEFORE the call, so by the time a malformed response is detected a
     * real Gmail watch subscription may already exist at Google. Discarding
     * the claim would orphan it — nothing local would point at a live
     * provider-side subscription — which is exactly the "rollback as
     * compensation for a call that already happened" pattern this
     * checkpoint removes.
     *
     * What must hold instead: the authorization survives, the claim
     * survives, exactly one route exists, no subscription row is invented
     * from an unparseable response, and the connection is honestly labelled
     * as not-yet-bootstrapped rather than silently broken.
     */
    public function test_a_malformed_watch_response_keeps_the_claim_and_degrades_honestly(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();

        $this->fakeGmailConnectWithMalformedWatchResponse('degrade-honestly-mailbox@example.test');

        $result = $this->connect($connection, $firmUser);

        // The OAuth authorization completed and was NOT discarded.
        $this->assertSame(ConnectionStatus::Active, $result->status);

        $fresh = $this->runWithFirmContext($firm, fn () => FirmIntegration::query()->where('id', $connection->id)->first());
        $this->assertSame(ConnectionStatus::Active, $fresh->status);
        $this->assertNotNull($fresh->webhook_routing_token, 'Webhook routing was enabled inside the OAuth transaction and stays enabled.');

        // The degradation is recorded rather than hidden.
        $this->assertTrue(
            $fresh->webhook_bootstrap_state->isDegraded(),
            'A connection whose bootstrap failed must never look fully healthy.'
        );

        // Exactly one route — the claim won before the call, neither
        // duplicated nor silently compensated away.
        $this->assertSame(
            1,
            DB::table('integration_gmail_mailbox_routes')->where('firm_integration_id', $connection->id)->count(),
            'The committed claim survives; it is not undone by a rollback.'
        );

        // And no subscription row invented from a response we could not parse.
        $this->assertSame(
            0,
            $this->runWithFirmContext($firm, fn () => DB::table('integration_provider_webhook_subscriptions')
                ->where('firm_integration_id', $connection->id)
                ->count()),
            'A subscription row must never be persisted from an unparseable provider response.'
        );
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function firmWithActiveKey(): Firm
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);

        return $firm;
    }

    private function firmUserFor(Firm $firm, FirmUserRole $role): FirmUser
    {
        $user = User::factory()->create();

        return $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->forUser($user)->role($role)->create());
    }

    /**
     * CHECKPOINT 8.2 corrective pass — HIGH finding 2.
     *
     * The typed refusal used to be swallowed: `subscribe()` runs inside
     * `OutboundProviderHttpClient::execute()`, whose catch-all converted
     * `GmailMailboxAlreadyRoutedException` into
     * `SanitizedProviderHttpException(UNKNOWN)`. Callers read UNKNOWN as
     * "the provider's outcome is ambiguous", so a DEFINITE local conflict —
     * detected before any request was sent — parked the connection in
     * `bootstrap_reconciliation_required`, a state with no correct
     * resolution and no automated exit.
     *
     * The exception now implements `LocalDomainFailureContract` and crosses
     * that boundary unchanged, so it reaches the branch written for it.
     */
    public function test_a_cross_firm_mailbox_conflict_reaches_the_definite_failure_state_not_reconciliation(): void
    {
        $routing = app(GmailMailboxRoutingService::class);
        $client = app(OutboundProviderHttpClient::class);

        [$firmA, $connectionA] = $this->committedFirmAndConnection();
        [$firmB, $connectionB] = $this->committedFirmAndConnection();

        $mailbox = 'boundary-preserved-mailbox@example.test';
        $this->withCommittedFirmContext($firmA, fn () => $routing->route($connectionA, $mailbox));

        // Exactly how the bootstrap invokes a provider: the claim happens
        // inside the closure the HTTP client wraps.
        $caught = null;

        try {
            $this->withCommittedFirmContext($firmB, fn () => $client->execute(
                fn () => $routing->route($connectionB, $mailbox),
                'subscribe',
            ));
        } catch (Throwable $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(
            GmailMailboxAlreadyRoutedException::class,
            $caught,
            'The typed local refusal must survive the sanitizing boundary intact.'
        );
        $this->assertNotInstanceOf(
            SanitizedProviderHttpException::class,
            $caught,
            'It must NOT be flattened into a sanitized provider failure — that is what made it look ambiguous.'
        );
        $this->assertSame('mailbox_already_routed', $caught->localFailureReason());

        // And the ownership guarantee still holds through the boundary.
        $this->assertSame(1, $this->committedRouteCountFor((int) $connectionA->id));
        $this->assertSame(0, $this->committedRouteCountFor((int) $connectionB->id));

        $this->withCommittedFirmContext($firmA, fn () => $routing->unroute($connectionA));
    }

    // ------------------------------------------------------------
    // Committed fixtures (§A7b). These must not depend on the outer test
    // transaction for setup OR teardown: the behaviour under test is
    // precisely that a claim COMMITS before any provider call. Created on
    // a genuinely separate, committing session; removed explicitly.
    // ------------------------------------------------------------

    private const COMMITTED_CONNECTION = 'pgsql_audit';

    /**
     * @return array{0: Firm, 1: FirmIntegration}
     */
    private function committedFirmAndConnection(): array
    {
        $firm = Firm::factory()->connection(self::COMMITTED_CONNECTION)->create();
        $this->setCommittedFirmContext((int) $firm->id);

        TenantEncryptionKey::factory()->connection(self::COMMITTED_CONNECTION)->forFirm($firm)->create();

        $connection = FirmIntegration::factory()
            ->connection(self::COMMITTED_CONNECTION)
            ->forFirm($firm)
            ->forProvider($this->googleWorkspaceProviderRow())
            ->create([
                'status' => ConnectionStatus::Active->value,
                'external_account_id' => null,
                'requested_capabilities_json' => [ResourceType::Message->value],
                // The factory default would create a FirmUser inside the
                // outer test transaction, invisible to this session.
                'connected_by_firm_user_id' => null,
            ]);

        $this->beforeApplicationDestroyed(function () use ($firm, $connection) {
            $committed = DB::connection(self::COMMITTED_CONNECTION);
            $this->setCommittedFirmContext((int) $firm->id);

            $committed->table('integration_gmail_mailbox_routes')->where('firm_integration_id', $connection->id)->delete();
            $committed->table('integration_provider_webhook_subscriptions')->where('firm_integration_id', $connection->id)->delete();
            $committed->table('integration_credentials')->where('firm_integration_id', $connection->id)->delete();
            $committed->table('timeline_events')->where('firm_id', $firm->id)->delete();
            $committed->table('firm_integrations')->where('id', $connection->id)->delete();
            $committed->table('tenant_encryption_keys')->where('firm_id', $firm->id)->delete();
            $committed->table('firms')->where('id', $firm->id)->delete();

            $this->clearCommittedFirmContext();
        });

        $this->clearCommittedFirmContext();

        return [$firm, $connection];
    }

    /**
     * Runs $callback with $firm's tenant context active on BOTH sessions —
     * the committed one the fixtures live on, and the default one the
     * service under test writes through.
     */
    private function withCommittedFirmContext(Firm $firm, callable $callback): mixed
    {
        $this->setCommittedFirmContext((int) $firm->id);

        try {
            return $this->runWithFirmContext($firm, $callback);
        } finally {
            $this->clearCommittedFirmContext();
        }
    }

    private function setCommittedFirmContext(int $firmId): void
    {
        DB::connection(self::COMMITTED_CONNECTION)
            ->select('select set_config(?, ?, ?)', ['app.current_firm_id', (string) $firmId, false]);
    }

    private function clearCommittedFirmContext(): void
    {
        DB::connection(self::COMMITTED_CONNECTION)
            ->select('select set_config(?, ?, ?)', ['app.current_firm_id', '', false]);
    }

    private function committedRouteForConnection(int $connectionId): ?object
    {
        return DB::table('integration_gmail_mailbox_routes')
            ->where('firm_integration_id', $connectionId)
            ->first();
    }

    /**
     * @param  array<int, int>  $connectionIds
     * @return array<int, object>
     */
    private function committedRoutesForConnections(array $connectionIds): array
    {
        return DB::table('integration_gmail_mailbox_routes')
            ->whereIn('firm_integration_id', $connectionIds)
            ->orderBy('id')
            ->get()
            ->all();
    }

    private function committedRouteCountFor(int $connectionId): int
    {
        return DB::table('integration_gmail_mailbox_routes')->where('firm_integration_id', $connectionId)->count();
    }

    /**
     * Both tables are FORCE-RLS protected, and the committed session is a
     * genuinely separate one, so each read needs its own tenant context
     * pushed and cleared again.
     */
    private function committedConnectionRow(Firm $firm, int $connectionId): object
    {
        $this->setCommittedFirmContext((int) $firm->id);

        try {
            $row = DB::connection(self::COMMITTED_CONNECTION)
                ->table('firm_integrations')
                ->where('id', $connectionId)
                ->first();

            $this->assertNotNull($row, 'The committed connection fixture must be visible to the committed session.');

            return $row;
        } finally {
            $this->clearCommittedFirmContext();
        }
    }

    private function committedCredentialCountFor(Firm $firm, int $connectionId): int
    {
        $this->setCommittedFirmContext((int) $firm->id);

        try {
            return DB::connection(self::COMMITTED_CONNECTION)
                ->table('integration_credentials')
                ->where('firm_integration_id', $connectionId)
                ->count();
        } finally {
            $this->clearCommittedFirmContext();
        }
    }

    private function googleWorkspaceProviderRow(): IntegrationProvider
    {
        return IntegrationProvider::query()->where('code', ProviderKey::GoogleWorkspace->value)->firstOrFail();
    }

    /**
     * @return array{0: Firm, 1: FirmIntegration, 2: FirmUser}
     */
    private function firmConnectionAndActor(): array
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->runWithFirmContext(
            $firm,
            // webhook_routing_token explicitly nulled: the factory's own
            // default (Str::random(40)) is a realistic value for an
            // ALREADY-connected fixture, but wrong for a genuinely
            // pending, not-yet-OAuth-completed connection — enableWebhookRouting()
            // is what's supposed to generate this token for the first
            // time on a successful connect. Without this override, the
            // rollback tests below would (correctly) restore the
            // factory's own pre-existing random token and appear to
            // fail, when the real bug would be this fixture's own wrong
            // starting state, not the transaction rollback itself.
            fn () => FirmIntegration::factory()
                ->forFirm($firm)
                ->forProvider($this->googleWorkspaceProviderRow())
                ->pending()
                ->create(['external_account_id' => null, 'requested_capabilities_json' => [ResourceType::Message->value], 'webhook_routing_token' => null])
        );
        $firmUser = $this->firmUserFor($firm, FirmUserRole::Attorney);

        return [$firm, $connection, $firmUser];
    }

    /**
     * @return OAuthCallbackResult
     */
    private function connect(FirmIntegration $connection, FirmUser $firmUser)
    {
        $redirectUri = route('integrations.oauth.callback', [], true);
        $service = app(ProviderConnectionService::class);
        $initiation = $service->initiateOAuthConnection($connection, $firmUser->user_id, $redirectUri);

        $query = [];
        parse_str((string) parse_url($initiation->authorizationUrl, PHP_URL_QUERY), $query);

        return $service->completeOAuthCallback($query['state'], 'unit-test-auth-code-'.$connection->id, $firmUser->user_id);
    }

    /**
     * Fakes a fully successful token-exchange + Gmail profile + Gmail
     * watch() sequence, routing to $emailAddress.
     *
     * @param  array<string, mixed>  $extraIdTokenClaims
     */
    private function fakeSuccessfulGmailConnect(string $emailAddress, array $extraIdTokenClaims = [], ?string $subSuffix = null): void
    {
        $idToken = $this->fakeIdToken(array_merge([
            'aud' => self::CLIENT_ID,
            'iss' => 'https://accounts.google.com',
            'sub' => 'google-sub-'.hash('crc32', $emailAddress.($subSuffix ?? '')),
            'hd' => 'example.test',
            'exp' => time() + 3600,
        ], $extraIdTokenClaims));

        Http::fake([
            self::TOKEN_BASE.'/token' => Http::response([
                'access_token' => 'fake-access-token',
                'refresh_token' => 'fake-refresh-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => self::REQUIRED_SCOPE,
                'id_token' => $idToken,
            ], 200),
            self::GMAIL_BASE.'/gmail/v1/users/me/profile' => Http::response([
                'emailAddress' => $emailAddress,
                'historyId' => '1000',
            ], 200),
            self::GMAIL_BASE.'/gmail/v1/users/me/watch' => Http::response([
                'historyId' => '1000',
                'expiration' => (string) now()->addDays(7)->getTimestampMs(),
            ], 200),
        ]);
    }

    private function fakeGmailConnectWithMalformedWatchResponse(string $emailAddress): void
    {
        $idToken = $this->fakeIdToken([
            'aud' => self::CLIENT_ID,
            'iss' => 'https://accounts.google.com',
            'sub' => 'google-sub-'.hash('crc32', $emailAddress),
            'hd' => 'example.test',
            'exp' => time() + 3600,
        ]);

        Http::fake([
            self::TOKEN_BASE.'/token' => Http::response([
                'access_token' => 'fake-access-token',
                'refresh_token' => 'fake-refresh-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => self::REQUIRED_SCOPE,
                'id_token' => $idToken,
            ], 200),
            self::GMAIL_BASE.'/gmail/v1/users/me/profile' => Http::response([
                'emailAddress' => $emailAddress,
                'historyId' => '1000',
            ], 200),
            // Deliberately no 'expiration' key at all — msEpochToIso8601()
            // returns null, so extractSubscriptionState() throws on an
            // unusable expires_at.
            self::GMAIL_BASE.'/gmail/v1/users/me/watch' => Http::response([
                'historyId' => '1000',
            ], 200),
        ]);
    }

    /**
     * Builds a base64url-encoded, UNSIGNED fake JWT (header.payload.signature)
     * carrying the given claims as its JSON payload — sufficient for
     * GoogleWorkspaceProvider::decodeAndValidateIdToken(), which only
     * ever base64url-decodes the payload segment and validates claims;
     * it never verifies a signature (identical, already-established
     * pattern to Microsoft365ProviderOAuthTest::fakeIdToken()).
     *
     * @param  array<string, mixed>  $claims
     */
    private function fakeIdToken(array $claims): string
    {
        $encode = static fn (array $data): string => rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        $header = $encode(['alg' => 'none', 'typ' => 'JWT']);
        $payload = $encode($claims);
        $signature = rtrim(strtr(base64_encode('unsigned-test-fixture-signature'), '+/', '-_'), '=');

        return "{$header}.{$payload}.{$signature}";
    }
}
