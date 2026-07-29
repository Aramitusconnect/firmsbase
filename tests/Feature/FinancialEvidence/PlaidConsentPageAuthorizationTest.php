<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialEvidence;

use App\Filament\ClientPortal\Pages\PlaidConsentPage;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Models\Client;
use App\Models\ClientPortalMatterGrant;
use App\Models\ClientPortalUser;
use App\Models\FinancialEvidenceClientConsent;
use App\Models\FinancialEvidenceMatterRequest;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\TimelineEvent;
use App\Services\ClientPortalPlaidConnectionResolverService;
use App\Services\TenantContextService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * PlaidConsentPageAuthorizationTest — release-candidate remediation,
 * defect H1 (High, IDOR).
 *
 * `PlaidConsentPage::resolveConnectionOrFail()` used to take the
 * connection id straight from the client-suppliable `#[Url]
 * $firmIntegration` Livewire property and validate it against `firm_id`
 * + provider ONLY — never against the current matter's own
 * `FinancialEvidenceMatterRequest`. Editing one query-string integer let
 * any authenticated portal client record a consent against (and, via the
 * revoke header action, disconnect) a DIFFERENT matter's Plaid
 * connection anywhere in the same firm. This is the same IDOR already
 * closed in `PlaidExchangeController::exchange()`, and these tests
 * mirror `PlaidExchangeControllerAuthorizationTest`'s proofs one for
 * one, at the page boundary instead of the route boundary.
 */
final class PlaidConsentPageAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('client-portal'));
    }

    /**
     * Positive control. The URL property is deliberately left NULL: the
     * connection is resolved entirely server-side from the matter's own
     * request, proving the query-string value is not the source of
     * authorization (and is not even required for the legitimate flow).
     */
    public function test_a_client_can_consent_for_their_own_matter_without_supplying_any_connection_id(): void
    {
        $firm = Firm::factory()->create();
        $fixture = $this->makeGrantedClientWithBoundRequest($firm);

        $this->grantConsentAs($fixture, urlFirmIntegration: null);

        $consent = $this->latestConsentFor($firm, $fixture['matter']);

        $this->assertNotNull($consent);
        $this->assertSame($fixture['connection']->id, $consent->firm_integration_id);
        $this->assertSame($fixture['request']->id, $consent->matter_request_id);
        $this->assertNotNull($consent->granted_at);
    }

    /**
     * THE regression proof for H1: client A has genuine portal access to
     * matter A, but hand-edits the `firmIntegration` query parameter to
     * matter B's connection id (same firm, different client). Before the
     * fix this recorded a consent binding client A's decision to client
     * B's bank connection.
     */
    public function test_a_client_cannot_consent_against_another_matters_connection_in_the_same_firm(): void
    {
        $firm = Firm::factory()->create();
        $fixtureA = $this->makeGrantedClientWithBoundRequest($firm);
        $fixtureB = $this->makeGrantedClientWithBoundRequest($firm);

        try {
            $this->grantConsentAs($fixtureA, urlFirmIntegration: (string) $fixtureB['connection']->id);
            $this->fail('A cross-matter connection id must be refused.');
        } catch (AccessDeniedHttpException) {
            // expected
        }

        $this->assertNull(
            $this->latestConsentFor($firm, $fixtureA['matter']),
            'A refused consent attempt must never write a consent row.'
        );

        $crossConsent = $this->runWithFirmContext($firm, fn () => FinancialEvidenceClientConsent::query()
            ->where('firm_integration_id', $fixtureB['connection']->id)
            ->exists());

        $this->assertFalse($crossConsent, "Matter B's connection must never receive matter A's client's consent.");

        $reloadedRequestB = $this->runWithFirmContext($firm, fn () => FinancialEvidenceMatterRequest::query()->find($fixtureB['request']->id));
        $this->assertSame('reviewed', $reloadedRequestB->status, "Matter B's request must be untouched.");
    }

    /**
     * The same tampering with an id that resolves to nothing at all —
     * proving the property is never treated as authoritative even when
     * the server binding is perfectly healthy.
     */
    public function test_changing_the_url_parameter_to_any_other_value_is_refused(): void
    {
        $firm = Firm::factory()->create();
        $fixture = $this->makeGrantedClientWithBoundRequest($firm);

        $this->expectException(AccessDeniedHttpException::class);

        $this->grantConsentAs($fixture, urlFirmIntegration: (string) ($fixture['connection']->id + 9_999));
    }

    /**
     * Same firm, but the client tries to act on a matter they hold no
     * `client_portal_matter_grants` row for — refused at the matter
     * boundary before any connection resolution happens at all.
     */
    public function test_a_client_cannot_act_on_a_different_matter_in_the_same_firm(): void
    {
        $firm = Firm::factory()->create();
        $fixtureA = $this->makeGrantedClientWithBoundRequest($firm);
        $fixtureB = $this->makeGrantedClientWithBoundRequest($firm);

        $spoofed = $fixtureA;
        $spoofed['matter'] = $fixtureB['matter'];

        $this->expectException(AccessDeniedHttpException::class);

        $this->grantConsentAs($spoofed, urlFirmIntegration: (string) $fixtureB['connection']->id);
    }

    public function test_a_connection_id_from_another_firm_is_refused(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $fixtureA = $this->makeGrantedClientWithBoundRequest($firmA);
        $fixtureB = $this->makeGrantedClientWithBoundRequest($firmB);

        try {
            $this->grantConsentAs($fixtureA, urlFirmIntegration: (string) $fixtureB['connection']->id);
            $this->fail('A cross-firm connection id must be refused.');
        } catch (AccessDeniedHttpException) {
            // expected
        }

        $this->assertNull($this->latestConsentFor($firmA, $fixtureA['matter']));
        $this->assertSame(
            0,
            $this->runWithFirmContext($firmB, fn () => FinancialEvidenceClientConsent::query()->count()),
            'Firm B must hold no consent row created by firm A\'s client.'
        );
    }

    public function test_a_cancelled_revoked_request_can_no_longer_be_consented_to(): void
    {
        $firm = Firm::factory()->create();
        $fixture = $this->makeGrantedClientWithBoundRequest($firm);

        $this->runWithFirmContext($firm, fn () => $fixture['request']->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]));

        $this->expectException(NotFoundHttpException::class);

        try {
            $this->grantConsentAs($fixture, urlFirmIntegration: (string) $fixture['connection']->id);
        } finally {
            $this->assertNull($this->latestConsentFor($firm, $fixture['matter']));
        }
    }

    public function test_a_disconnected_connection_can_no_longer_be_consented_to(): void
    {
        $firm = Firm::factory()->create();
        $fixture = $this->makeGrantedClientWithBoundRequest($firm, connectionState: 'disconnected');

        $this->expectException(AccessDeniedHttpException::class);

        $this->grantConsentAs($fixture, urlFirmIntegration: (string) $fixture['connection']->id);
    }

    public function test_a_refused_consent_attempt_is_audited_with_a_reason_and_no_sensitive_value(): void
    {
        $firm = Firm::factory()->create();
        $fixtureA = $this->makeGrantedClientWithBoundRequest($firm);
        $fixtureB = $this->makeGrantedClientWithBoundRequest($firm);

        try {
            $this->grantConsentAs($fixtureA, urlFirmIntegration: (string) $fixtureB['connection']->id);
            $this->fail('A cross-matter connection id must be refused.');
        } catch (AccessDeniedHttpException) {
            // expected
        }

        $event = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', ClientPortalPlaidConnectionResolverService::DENIAL_EVENT_TYPE)
            ->latest('id')
            ->first());

        $this->assertNotNull($event);

        $metadata = $event->metadata_json;
        $this->assertSame('grant_consent', $metadata['action']);
        $this->assertSame('submitted_connection_id_does_not_match_binding', $metadata['reason']);
        $this->assertSame($fixtureB['connection']->id, $metadata['submitted_firm_integration_id']);
        $this->assertSame($fixtureA['connection']->id, $metadata['bound_firm_integration_id']);

        foreach (['access_token', 'public_token', 'link_token', 'token', 'secret', 'account_number', 'mask', 'password', 'payload', 'body'] as $forbiddenKey) {
            $this->assertArrayNotHasKey($forbiddenKey, $metadata, "Denial audit metadata must never carry a '{$forbiddenKey}' key.");
        }
    }

    /**
     * Declining is deliberately untouched by this fix — it records a
     * consent row with no connection at all, so it never needed (and
     * still must not acquire) a connection-resolution step.
     */
    public function test_declining_still_works_without_any_connection_binding(): void
    {
        $firm = Firm::factory()->create();
        $fixture = $this->makeGrantedClientWithBoundRequest($firm);

        Auth::guard('client')->login($fixture['portalUser']);

        $page = new PlaidConsentPage;
        $page->matter = (string) $fixture['matter']->id;

        $this->withinClientPortalRequest($firm, fn () => $page->declineConsent());

        $consent = $this->latestConsentFor($firm, $fixture['matter']);

        $this->assertNotNull($consent);
        $this->assertNotNull($consent->declined_at);
        $this->assertNull($consent->firm_integration_id);
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * @param  array{firm: Firm, matter: Matter, portalUser: ClientPortalUser, connection: FirmIntegration, request: FinancialEvidenceMatterRequest}  $fixture
     */
    private function grantConsentAs(array $fixture, ?string $urlFirmIntegration): void
    {
        Auth::guard('client')->login($fixture['portalUser']);

        $page = new PlaidConsentPage;
        $page->matter = (string) $fixture['matter']->id;
        $page->firmIntegration = $urlFirmIntegration;

        $this->withinClientPortalRequest($fixture['firm'], fn () => $page->grantConsent());
    }

    /**
     * Ambient, non-transaction-scoped tenant context — the shape
     * `EstablishClientPortalTenantContext` establishes for a real
     * request. Deliberately not `runWithFirmContext()`, whose
     * transaction would roll back (and so hide) the fail-closed denial
     * audit row written immediately before the exception is thrown.
     */
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

    private function latestConsentFor(Firm $firm, Matter $matter): ?FinancialEvidenceClientConsent
    {
        return $this->runWithFirmContext($firm, fn () => FinancialEvidenceClientConsent::query()
            ->where('matter_id', $matter->id)
            ->latest('id')
            ->first());
    }

    /**
     * @return array{firm: Firm, matter: Matter, portalUser: ClientPortalUser, connection: FirmIntegration, request: FinancialEvidenceMatterRequest}
     */
    private function makeGrantedClientWithBoundRequest(Firm $firm, string $connectionState = 'active'): array
    {
        return $this->runWithFirmContext($firm, function () use ($firm, $connectionState): array {
            $client = Client::factory()->forFirm($firm)->create();
            $matter = Matter::factory()->forFirm($firm)->forClient($client)->create();

            $plaidProvider = IntegrationProvider::query()->where('code', ProviderKey::Plaid->value)->firstOrFail();

            $factory = FirmIntegration::factory()->forFirm($firm)->forProvider($plaidProvider);
            $connection = ($connectionState === 'disconnected' ? $factory->disconnected() : $factory)->create();

            $requestedBy = FirmUser::factory()->create(['firm_id' => $firm->id]);

            $request = FinancialEvidenceMatterRequest::query()->create([
                'firm_id' => $firm->id,
                'matter_id' => $matter->id,
                'firm_integration_id' => $connection->id,
                'requested_by_firm_user_id' => $requestedBy->id,
                'purpose' => 'Verify income for support calculation.',
                'requested_products_json' => ['bank_account', 'transaction'],
                'status' => 'reviewed',
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
    }
}
