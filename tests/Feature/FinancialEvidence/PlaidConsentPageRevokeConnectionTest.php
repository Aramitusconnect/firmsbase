<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialEvidence;

use App\Enums\EntitlementSource;
use App\Filament\ClientPortal\Pages\PlaidConsentPage;
use App\Filament\ClientPortal\Pages\PlaidDateRangeConfirmationPage;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\IntegrationCredentialStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Providers\Plaid\PlaidProvider;
use App\Integrations\Services\FinancialEvidenceMatterScopeService;
use App\Models\Client;
use App\Models\ClientPortalMatterGrant;
use App\Models\ClientPortalUser;
use App\Models\FinancialEvidenceClientConsent;
use App\Models\FinancialEvidenceMatterAuthorization;
use App\Models\FinancialEvidenceMatterRequest;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\TenantEncryptionKey;
use App\Models\TimelineEvent;
use App\Services\ClientPortalPlaidConnectionResolverService;
use App\Services\EntitlementService;
use App\Services\TenantContextService;
use Filament\Actions\Action as FilamentAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlaidConsentPageRevokeConnectionTest — release-candidate remediation,
 * defect H5 (High).
 *
 * `PlaidConsentPage`'s "Revoke Connection" header action — §4.9's ONLY
 * client-initiated disconnect path — called
 * `ProviderConnectionService::disconnect($connection)` with NO actor
 * argument at all. That method's own first guard throws
 * `RuntimeException('disconnect() requires either a FirmUser
 * $currentUserId or an admin $actorPlatformAdminId.')` whenever both are
 * null, and the call sat OUTSIDE the try/catch that wrapped the
 * neighbouring resolve step — so every single client revoke attempt
 * produced an uncaught 500 and the feature had never worked once. No
 * test covered it, which is how a 100%-reproducible failure reached a
 * release candidate.
 *
 * These tests drive the REAL Filament header action through Livewire
 * (`mountAction('revoke')` / `callMountedAction()`), on a connection
 * built by the REAL Plaid Link exchange route — the same
 * `Http::fake()`-backed sandbox fixture
 * `PlaidExchangeControllerAuthorizationTest` established. No real
 * network call is ever made (the suite-wide
 * `Http::preventStrayRequests()` guard in Tests\TestCase would fail
 * loudly if one were attempted).
 */
final class PlaidConsentPageRevokeConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'unit-test-plaid-client-id-rc-revoke';

    private const SECRET = 'unit-test-plaid-secret-rc-revoke';

    private const SANDBOX_BASE = 'https://sandbox.plaid.test';

    /**
     * The Plaid Item the NEXT `/portal/plaid/exchange` fixture call will
     * be told it connected. Mutable per fixture because
     * `firm_integrations` carries a partial unique index on
     * (firm_id, integration_provider_id, external_account_id): two
     * connections in the SAME firm must return two different item ids.
     * Read through the single closure-backed `Http::fake()` registered in
     * setUp() — re-registering `Http::fake()` mid-test merges rather than
     * replaces its stubs, which silently served the first fixture's item
     * id to the second one.
     */
    private string $nextItemId = 'item-rc-revoke-default';

    private string $nextInstitutionId = 'ins_rc_revoke_default';

    private bool $providerRevokeFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('client-portal'));

        config(['integrations.providers' => [ProviderKey::Plaid->value => PlaidProvider::class]]);
        config([
            'integrations.oauth_apps.plaid.client_id' => self::CLIENT_ID,
            'integrations.oauth_apps.plaid.secret' => self::SECRET,
            'integrations.oauth_apps.plaid.webhook_url' => 'https://app.firmsbase.test/integrations/webhooks/plaid',
            'integrations.oauth_apps.plaid.item_routing_hmac_key' => str_repeat('k', 32),
            'integrations.provider_environments.'.ProviderKey::Plaid->value => [
                'mode' => 'sandbox',
                'sandbox_base_urls' => ['default' => self::SANDBOX_BASE],
                'live_base_urls' => ['default' => self::SANDBOX_BASE],
            ],
        ]);

        // Every Plaid endpoint this feature touches, faked once, for the
        // whole test. No real network call is possible (Tests\TestCase
        // installs Http::preventStrayRequests() suite-wide).
        Http::fake([
            self::SANDBOX_BASE.'/item/public_token/exchange' => fn () => Http::response([
                'access_token' => 'access-sandbox-fixture-token-rc-revoke',
                'item_id' => $this->nextItemId,
            ], 200),
            self::SANDBOX_BASE.'/item/get' => fn () => Http::response([
                'item' => ['item_id' => $this->nextItemId, 'institution_id' => $this->nextInstitutionId],
            ], 200),
            self::SANDBOX_BASE.'/item/remove' => fn () => $this->providerRevokeFails
                ? Http::response(['error_code' => 'INTERNAL_SERVER_ERROR'], 500)
                : Http::response(['request_id' => 'req-rc-revoke'], 200),
        ]);
    }

    public function test_a_client_can_revoke_their_own_connection_and_the_disconnect_actually_happens(): void
    {
        $fixture = $this->makeConsentedConnection('item-rc-revoke-a', 'ins_rc_revoke_a');

        $this->revokeAs($fixture);

        $connection = $this->reloadConnection($fixture);

        $this->assertSame(ConnectionStatus::Disconnected, $connection->status, 'The revoke action must actually disconnect the connection.');
        $this->assertNotNull($connection->disconnected_at);
        $this->assertNull($connection->webhook_routing_token, 'Revoking must stop future webhook-driven sync.');
    }

    public function test_revoking_transitions_every_active_credential_to_revoked(): void
    {
        $fixture = $this->makeConsentedConnection('item-rc-revoke-cred', 'ins_rc_revoke_cred');

        $activeBefore = $this->runWithFirmContext($fixture['firm'], fn () => IntegrationCredential::query()
            ->where('firm_integration_id', $fixture['connection']->id)
            ->where('credential_type', CredentialType::ProviderAccessToken->value)
            ->where('status', IntegrationCredentialStatus::Active->value)
            ->count());

        $this->assertSame(1, $activeBefore, 'Fixture precondition: the exchanged connection must hold exactly one Active Plaid access token.');

        $this->revokeAs($fixture);

        $credentials = $this->runWithFirmContext($fixture['firm'], fn () => IntegrationCredential::query()
            ->where('firm_integration_id', $fixture['connection']->id)
            ->get());

        $this->assertNotEmpty($credentials, 'Credential rows must be transitioned, never deleted — the evidence trail is preserved.');

        foreach ($credentials as $credential) {
            $this->assertSame(IntegrationCredentialStatus::Revoked, $credential->status);
            $this->assertNotNull($credential->revoked_at);
        }
    }

    public function test_access_to_the_matters_financial_evidence_is_removed_immediately_after_a_revoke(): void
    {
        $fixture = $this->makeConsentedConnection('item-rc-revoke-scope', 'ins_rc_revoke_scope');

        $before = $this->runWithFirmContext($fixture['firm'], fn () => app(FinancialEvidenceMatterScopeService::class)
            ->connectedFirmIntegrationIds($fixture['matter']));

        $this->assertSame([$fixture['connection']->id], array_values($before), 'Fixture precondition: the matter must currently be authorized for this connection.');

        $this->revokeAs($fixture);

        $after = $this->runWithFirmContext($fixture['firm'], fn () => app(FinancialEvidenceMatterScopeService::class)
            ->connectedFirmIntegrationIds($fixture['matter']));

        $this->assertSame([], array_values($after), 'Every workspace panel resolves access through this service — a revoke must remove it immediately.');

        $superseded = $this->runWithFirmContext($fixture['firm'], fn () => FinancialEvidenceMatterAuthorization::query()
            ->where('matter_id', $fixture['matter']->id)
            ->where('firm_integration_id', $fixture['connection']->id)
            ->get());

        $this->assertNotEmpty($superseded, 'Authorization rows are superseded, never deleted.');
        foreach ($superseded as $authorization) {
            $this->assertNotNull($authorization->superseded_at);
        }
    }

    public function test_revoking_records_the_client_consent_withdrawal_without_deleting_the_original_grant(): void
    {
        $fixture = $this->makeConsentedConnection('item-rc-revoke-consent', 'ins_rc_revoke_consent');

        $this->revokeAs($fixture);

        $consents = $this->runWithFirmContext($fixture['firm'], fn () => FinancialEvidenceClientConsent::query()
            ->where('matter_id', $fixture['matter']->id)
            ->orderBy('id')
            ->get());

        $this->assertCount(2, $consents, 'The original grant row must be preserved and a withdrawal row appended.');
        $this->assertNotNull($consents[0]->granted_at, 'The original consent grant must remain intact.');
        $this->assertNotNull($consents[1]->declined_at);
        $this->assertSame([], $consents[1]->granted_products_json);
        $this->assertSame($fixture['connection']->id, $consents[1]->firm_integration_id);

        $request = $this->runWithFirmContext($fixture['firm'], fn () => FinancialEvidenceMatterRequest::query()->find($fixture['request']->id));
        $this->assertSame('declined', $request->status);
    }

    public function test_revoking_records_an_audit_event_carrying_no_sensitive_value(): void
    {
        $fixture = $this->makeConsentedConnection('item-rc-revoke-audit', 'ins_rc_revoke_audit');

        $this->revokeAs($fixture);

        $events = $this->runWithFirmContext($fixture['firm'], fn () => TimelineEvent::query()
            ->where('firm_id', $fixture['firm']->id)
            ->get());

        $eventTypes = $events->pluck('event_type')->all();

        $this->assertContains(
            ClientPortalPlaidConnectionResolverService::REVOCATION_EVENT_TYPE,
            $eventTypes,
            'A client-initiated revoke must leave its own portal-side audit record.'
        );
        $this->assertContains('integration_oauth.disconnect', $eventTypes, 'ProviderConnectionService\'s own disconnect audit trail must be preserved.');
        $this->assertContains('integration_oauth.credential_revoked', $eventTypes);

        $revocationEvent = $events->firstWhere('event_type', ClientPortalPlaidConnectionResolverService::REVOCATION_EVENT_TYPE);
        $metadata = $revocationEvent->metadata_json;

        $this->assertSame($fixture['connection']->id, $metadata['firm_integration_id']);
        $this->assertSame('client_portal', $metadata['initiated_by']);

        $encoded = json_encode($events->pluck('metadata_json')->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('access-sandbox', $encoded, 'No access token may ever appear in a timeline event.');
        $this->assertStringNotContainsString(self::SECRET, $encoded, 'No platform Plaid secret may ever appear in a timeline event.');
    }

    /**
     * Repeat-revoke must be a safe no-op, not a 500 and not a corrupted
     * state — `disconnect()` is itself idempotent and the local
     * consent/authorization writes below must be too.
     */
    public function test_revoking_twice_is_idempotent_and_never_throws(): void
    {
        $fixture = $this->makeConsentedConnection('item-rc-revoke-twice', 'ins_rc_revoke_twice');

        $this->revokeAs($fixture);

        $connectionAfterFirst = $this->reloadConnection($fixture);
        $disconnectedAt = $connectionAfterFirst->disconnected_at;

        $this->revokeAs($fixture);

        $connectionAfterSecond = $this->reloadConnection($fixture);

        $this->assertSame(ConnectionStatus::Disconnected, $connectionAfterSecond->status);
        $this->assertSame(
            $disconnectedAt?->toDateTimeString(),
            $connectionAfterSecond->disconnected_at?->toDateTimeString(),
            'A repeat revoke must never overwrite the original disconnected_at.'
        );

        $request = $this->runWithFirmContext($fixture['firm'], fn () => FinancialEvidenceMatterRequest::query()->find($fixture['request']->id));
        $this->assertSame('declined', $request->status);
    }

    /**
     * A provider-side failure (Plaid `/item/remove` returning 500) must
     * never block local teardown, and must never surface a raw provider
     * message to the client.
     */
    public function test_a_provider_failure_is_handled_gracefully_and_local_teardown_still_completes(): void
    {
        $fixture = $this->makeConsentedConnection('item-rc-revoke-fail', 'ins_rc_revoke_fail');

        $this->providerRevokeFails = true;

        $this->revokeAs($fixture);

        $connection = $this->reloadConnection($fixture);

        $this->assertSame(ConnectionStatus::Disconnected, $connection->status, 'Local teardown must proceed even when the provider revoke fails.');

        $request = $this->runWithFirmContext($fixture['firm'], fn () => FinancialEvidenceMatterRequest::query()->find($fixture['request']->id));
        $this->assertSame('declined', $request->status);
    }

    /**
     * Cross-matter: client A aims the URL property at client B's
     * connection while acting on their own matter. The revoke must be
     * refused, and — critically — client B's connection must remain
     * fully intact.
     */
    public function test_a_client_cannot_revoke_another_matters_connection_in_the_same_firm(): void
    {
        $fixtureA = $this->makeConsentedConnection('item-rc-revoke-xa', 'ins_rc_revoke_xa');
        $fixtureB = $this->makeConsentedConnection('item-rc-revoke-xb', 'ins_rc_revoke_xb', $fixtureA['firm']);

        $this->revokeAs($fixtureA, urlFirmIntegration: (string) $fixtureB['connection']->id);

        $connectionB = $this->runWithFirmContext($fixtureB['firm'], fn () => FirmIntegration::query()->find($fixtureB['connection']->id));
        $connectionA = $this->reloadConnection($fixtureA);

        $this->assertSame(ConnectionStatus::Active, $connectionB->status, "Matter B's connection must be untouched by matter A's client.");
        $this->assertSame(ConnectionStatus::Active, $connectionA->status, 'A refused revoke must not silently fall back to disconnecting the actor\'s own connection either.');

        $denial = $this->runWithFirmContext($fixtureA['firm'], fn () => TimelineEvent::query()
            ->where('event_type', ClientPortalPlaidConnectionResolverService::DENIAL_EVENT_TYPE)
            ->latest('id')
            ->first());

        $this->assertNotNull($denial, 'A refused revoke must be audited.');
        $this->assertSame('revoke_connection', $denial->metadata_json['action']);
    }

    /**
     * Cross-client: a second client of the same firm, with no portal
     * grant for this matter, cannot revoke it.
     */
    public function test_a_client_without_a_grant_for_the_matter_cannot_revoke_its_connection(): void
    {
        $fixture = $this->makeConsentedConnection('item-rc-revoke-nogrant', 'ins_rc_revoke_nogrant');

        $strangerFixture = $this->makeConsentedConnection('item-rc-revoke-stranger', 'ins_rc_revoke_stranger', $fixture['firm']);

        // The stranger is a real, authenticated portal client of the same
        // firm — but holds no ClientPortalMatterGrant for this matter.
        $spoofed = $fixture;
        $spoofed['portalUser'] = $strangerFixture['portalUser'];

        $this->revokeViaHeaderActionAs($spoofed, urlFirmIntegration: (string) $fixture['connection']->id);

        $this->assertSame(
            ConnectionStatus::Active,
            $this->reloadConnection($fixture)->status,
            'A client with no grant for this matter must never be able to revoke its connection.'
        );

        $this->assertSame(
            1,
            $this->runWithFirmContext($fixture['firm'], fn () => FinancialEvidenceClientConsent::query()
                ->where('matter_id', $fixture['matter']->id)
                ->count()),
            'A refused revoke must not append a consent-withdrawal row.'
        );
    }

    public function test_a_cross_firm_client_cannot_revoke_the_connection(): void
    {
        $fixtureA = $this->makeConsentedConnection('item-rc-revoke-firma', 'ins_rc_revoke_firma');
        $fixtureB = $this->makeConsentedConnection('item-rc-revoke-firmb', 'ins_rc_revoke_firmb');

        $spoofed = $fixtureA;
        $spoofed['portalUser'] = $fixtureB['portalUser'];

        $this->revokeViaHeaderActionAs($spoofed, urlFirmIntegration: (string) $fixtureA['connection']->id);

        $this->assertSame(
            ConnectionStatus::Active,
            $this->reloadConnection($fixtureA)->status,
            "A client of another firm must never be able to revoke firm A's connection."
        );
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * Drives the REAL Filament header action end to end, under ambient
     * (non-transaction-scoped) tenant context — the shape
     * `EstablishClientPortalTenantContext` establishes for a real
     * request. Never asserts on an exception escaping: the whole point
     * of defect H5's fix is that no failure path may produce an uncaught
     * 500, so a throw here fails the test by itself.
     *
     * @param  array{firm: Firm, matter: Matter, portalUser: ClientPortalUser, connection: FirmIntegration, request: FinancialEvidenceMatterRequest}  $fixture
     */
    private function revokeAs(array $fixture, ?string $urlFirmIntegration = null): void
    {
        Auth::guard('client')->login($fixture['portalUser']);

        $this->withinClientPortalRequest($fixture['firm'], function () use ($fixture, $urlFirmIntegration): void {
            $test = Livewire::test(PlaidConsentPage::class, [
                'matter' => (string) $fixture['matter']->id,
                'firmIntegration' => $urlFirmIntegration ?? (string) $fixture['connection']->id,
            ]);

            $test->mountAction('revoke');
            $test->callMountedAction();
        });
    }

    /**
     * Same real header action, invoked directly on the page object
     * rather than through `Livewire::test()`. Used for the two
     * unauthorized-actor scenarios where the page cannot legitimately
     * RENDER at all (`resolveMatterOrFail()` refuses the matter itself,
     * which is the correct fail-closed behavior and leaves Livewire's
     * test harness without a mountable component). Invoking the action
     * object directly proves the action's own guard independently of
     * whether the surrounding page happened to render.
     *
     * @param  array{firm: Firm, matter: Matter, portalUser: ClientPortalUser, connection: FirmIntegration, request: FinancialEvidenceMatterRequest}  $fixture
     */
    private function revokeViaHeaderActionAs(array $fixture, ?string $urlFirmIntegration = null): void
    {
        Auth::guard('client')->login($fixture['portalUser']);

        $this->withinClientPortalRequest($fixture['firm'], function () use ($fixture, $urlFirmIntegration): void {
            $page = new PlaidConsentPage;
            $page->matter = (string) $fixture['matter']->id;
            $page->firmIntegration = $urlFirmIntegration ?? (string) $fixture['connection']->id;

            /** @var FilamentAction[] $actions */
            $actions = (fn () => $this->getHeaderActions())->call($page);

            $revoke = collect($actions)->first(fn (FilamentAction $action): bool => $action->getName() === 'revoke');

            $this->assertNotNull($revoke, 'The revoke header action must exist.');

            $revoke->call();
        });
    }

    private function withinClientPortalRequest(Firm $firm, callable $callback): mixed
    {
        $tenant = new TenantContextService;
        $tenant->setFirmContext($firm);
        $tenant->setDatabaseTenantContextForFirmId($firm->id);

        try {
            return $callback();
        } finally {
            $tenant->clearDatabaseTenantContext();
            $tenant->clearFirmContext();
        }
    }

    /**
     * @param  array{firm: Firm, connection: FirmIntegration}  $fixture
     */
    private function reloadConnection(array $fixture): FirmIntegration
    {
        return $this->runWithFirmContext($fixture['firm'], fn () => FirmIntegration::query()->findOrFail($fixture['connection']->id));
    }

    /**
     * Builds a genuinely connected, date-range-confirmed, consented
     * matter: the connection is created by the real
     * `startConnection()`-shaped fixture, activated through the REAL
     * `/portal/plaid/exchange` route (so a real
     * `integration_credentials` row exists to revoke), then walked
     * through the real date-range and consent pages.
     *
     * @return array{firm: Firm, matter: Matter, portalUser: ClientPortalUser, connection: FirmIntegration, request: FinancialEvidenceMatterRequest}
     */
    private function makeConsentedConnection(string $itemId, string $institutionId, ?Firm $firm = null): array
    {
        $firm ??= Firm::factory()->create();

        $fixture = $this->runWithFirmContext($firm, function () use ($firm): array {
            TenantEncryptionKey::query()->where('firm_id', $firm->id)->exists()
                || TenantEncryptionKey::factory()->forFirm($firm)->create();

            app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);
            app(EntitlementService::class)->setForSource($firm, 'plaid', EntitlementSource::AdminOverride, true);

            $client = Client::factory()->forFirm($firm)->create();
            $matter = Matter::factory()->forFirm($firm)->forClient($client)->create();

            $plaidProvider = IntegrationProvider::query()->where('code', ProviderKey::Plaid->value)->firstOrFail();
            $connection = FirmIntegration::factory()
                ->forFirm($firm)
                ->forProvider($plaidProvider)
                ->pending()
                ->create(['external_account_id' => null]);

            $requestedBy = FirmUser::factory()->create(['firm_id' => $firm->id]);

            $request = FinancialEvidenceMatterRequest::query()->create([
                'firm_id' => $firm->id,
                'matter_id' => $matter->id,
                'firm_integration_id' => $connection->id,
                'requested_by_firm_user_id' => $requestedBy->id,
                'purpose' => 'Verify income for support calculation.',
                'requested_products_json' => ['bank_account', 'transaction'],
                'status' => 'pending',
                'requested_at' => now(),
            ]);

            $portalUser = ClientPortalUser::query()->create([
                'client_id' => $client->id,
                'email' => 'client-'.Str::random(10).'@example.test',
                'password' => 'irrelevant-hashed-value',
                'is_active' => true,
            ]);

            ClientPortalMatterGrant::query()->create([
                'firm_id' => $firm->id,
                'client_id' => $client->id,
                'matter_id' => $matter->id,
                'granted_at' => now(),
            ]);

            return [
                'firm' => $firm,
                'matter' => $matter,
                'portalUser' => $portalUser,
                'connection' => $connection,
                'request' => $request,
            ];
        });

        $this->nextItemId = $itemId;
        $this->nextInstitutionId = $institutionId;

        $this->actingAs($fixture['portalUser'], 'client')
            ->post($this->clientPortalUrl('/plaid/exchange'), [
                'public_token' => 'public-sandbox-fixture-token',
                'firm_integration_id' => $fixture['connection']->id,
                'matter_id' => $fixture['matter']->id,
            ])->assertOk();

        Auth::guard('client')->login($fixture['portalUser']);

        $this->withinClientPortalRequest($firm, function () use ($fixture): void {
            $dateRangePage = new PlaidDateRangeConfirmationPage;
            $dateRangePage->matter = (string) $fixture['matter']->id;
            $dateRangePage->mount();
            $dateRangePage->continueToConsent();

            $consentPage = new PlaidConsentPage;
            $consentPage->matter = (string) $fixture['matter']->id;
            $consentPage->firmIntegration = (string) $fixture['connection']->id;
            $consentPage->grantConsent();
        });

        $fixture['request'] = $this->runWithFirmContext($firm, fn () => FinancialEvidenceMatterRequest::query()->findOrFail($fixture['request']->id));

        return $fixture;
    }
}
