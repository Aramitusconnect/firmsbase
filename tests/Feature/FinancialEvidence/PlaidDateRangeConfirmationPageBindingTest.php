<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialEvidence;

use App\Filament\ClientPortal\Pages\PlaidDateRangeConfirmationPage;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Models\Client;
use App\Models\ClientPortalMatterGrant;
use App\Models\ClientPortalUser;
use App\Models\FinancialEvidenceMatterAuthorization;
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
 * PlaidDateRangeConfirmationPageBindingTest — release-candidate
 * remediation, defect C1 (Critical).
 *
 * `PlaidDateRangeConfirmationPage::continueToConsent()` used to pick the
 * connection it writes
 * `financial_evidence_matter_authorizations.firm_integration_id` from
 * with a firm-wide "newest Active Plaid connection" query
 * (`->where('firm_id', ...)->where('status', 'active')->latest('id')->first()`).
 * Nothing in that query mentioned the matter, the request, the client,
 * or the consent — so in any firm with two clients connecting accounts,
 * whichever client finished Plaid Link LAST owned the row every other
 * client's confirmation then bound to. No test existed for this page at
 * all before this file (confirmed repo-wide), which is exactly how a
 * defect this mechanical survived to a release candidate.
 *
 * Every test below drives the REAL page object under the REAL `client`
 * guard, with a real ClientPortalUser/ClientPortalMatterGrant identity
 * — the same shape FinancialEvidenceClientPortalConsentFlowTest
 * established for this panel.
 */
final class PlaidDateRangeConfirmationPageBindingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // continueToConsent() redirects via PlaidConsentPage::getUrl(),
        // which Filament resolves against the currently active panel —
        // never set for a direct method call in a test.
        Filament::setCurrentPanel(Filament::getPanel('client-portal'));
    }

    /**
     * THE regression proof for C1. Client B's connection is created
     * strictly AFTER client A's request already exists and already
     * carries its own binding — the ordinary, entirely legitimate
     * concurrent-use ordering that the pre-fix `latest('id')` query
     * turned into a cross-client data-binding bug. Client A must still
     * bind to client A's connection.
     */
    public function test_client_a_binds_to_its_own_connection_even_when_client_b_connects_afterwards(): void
    {
        $firm = Firm::factory()->create();
        $fixtureA = $this->makeGrantedClientWithBoundRequest($firm);

        // Client B connects LAST — a strictly higher firm_integrations.id.
        $fixtureB = $this->makeGrantedClientWithBoundRequest($firm);
        $this->assertGreaterThan(
            $fixtureA['connection']->id,
            $fixtureB['connection']->id,
            'Fixture precondition: client B\'s connection must be the newer row, or this test cannot detect the defect.'
        );

        $this->confirmDateRangeAs($fixtureA);

        $authorization = $this->liveAuthorizationFor($firm, $fixtureA['matter']);

        $this->assertNotNull($authorization);
        $this->assertSame(
            $fixtureA['connection']->id,
            $authorization->firm_integration_id,
            'Matter A must bind to the connection created for matter A\'s own request.'
        );
        $this->assertNotSame(
            $fixtureB['connection']->id,
            $authorization->firm_integration_id,
            'Creation order must never decide which client\'s bank connection a matter is bound to.'
        );
    }

    /**
     * Both clients complete the same step; each matter's authorization
     * must reference only its own request's connection. Proves the fix
     * is a genuine per-request binding, not merely "the first one wins."
     */
    public function test_two_clients_in_one_firm_each_bind_only_to_their_own_requests_connection(): void
    {
        $firm = Firm::factory()->create();
        $fixtureA = $this->makeGrantedClientWithBoundRequest($firm);
        $fixtureB = $this->makeGrantedClientWithBoundRequest($firm);

        $this->confirmDateRangeAs($fixtureA);
        $this->confirmDateRangeAs($fixtureB);

        $this->assertSame(
            $fixtureA['connection']->id,
            $this->liveAuthorizationFor($firm, $fixtureA['matter'])?->firm_integration_id,
        );
        $this->assertSame(
            $fixtureB['connection']->id,
            $this->liveAuthorizationFor($firm, $fixtureB['matter'])?->firm_integration_id,
        );

        $crossBound = $this->runWithFirmContext($firm, fn () => FinancialEvidenceMatterAuthorization::query()
            ->where('matter_id', $fixtureA['matter']->id)
            ->where('firm_integration_id', $fixtureB['connection']->id)
            ->exists());

        $this->assertFalse($crossBound, 'Matter A must never hold an authorization row pointing at client B\'s connection.');
    }

    public function test_a_bound_connection_that_is_not_active_is_refused(): void
    {
        $firm = Firm::factory()->create();
        $fixture = $this->makeGrantedClientWithBoundRequest($firm, connectionState: 'pending');

        $this->expectException(AccessDeniedHttpException::class);

        try {
            $this->confirmDateRangeAs($fixture);
        } finally {
            $this->assertNull(
                $this->liveAuthorizationFor($firm, $fixture['matter']),
                'A refused confirmation must never write an authorization row.'
            );
        }
    }

    public function test_a_bound_connection_that_has_been_revoked_disconnected_is_refused(): void
    {
        $firm = Firm::factory()->create();
        $fixture = $this->makeGrantedClientWithBoundRequest($firm, connectionState: 'disconnected');

        $this->expectException(AccessDeniedHttpException::class);

        $this->confirmDateRangeAs($fixture);
    }

    /**
     * The request's binding column carries an id that only resolves in a
     * DIFFERENT firm (the FK on that column is a plain
     * `constrained('firm_integrations')`, so a cross-firm id is
     * physically storable). The resolver must refuse rather than reach
     * across the tenant boundary.
     */
    public function test_a_bound_connection_belonging_to_another_firm_is_refused(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $fixtureA = $this->makeGrantedClientWithBoundRequest($firmA);
        $fixtureB = $this->makeGrantedClientWithBoundRequest($firmB);

        $this->runWithFirmContext($firmA, fn () => $fixtureA['request']->update([
            'firm_integration_id' => $fixtureB['connection']->id,
        ]));

        $this->expectException(AccessDeniedHttpException::class);

        try {
            $this->confirmDateRangeAs($fixtureA);
        } finally {
            $this->assertNull($this->liveAuthorizationFor($firmA, $fixtureA['matter']));
        }
    }

    public function test_a_matter_whose_request_carries_no_connection_binding_is_refused(): void
    {
        $firm = Firm::factory()->create();
        $fixture = $this->makeGrantedClientWithBoundRequest($firm);

        $this->runWithFirmContext($firm, fn () => $fixture['request']->update(['firm_integration_id' => null]));

        $this->expectException(NotFoundHttpException::class);

        try {
            $this->confirmDateRangeAs($fixture);
        } finally {
            $this->assertNull($this->liveAuthorizationFor($firm, $fixture['matter']));
        }
    }

    /**
     * A cancelled request is no longer a live binding source, even
     * though its `firm_integration_id` column still holds a real,
     * same-firm, Active connection.
     */
    public function test_a_cancelled_request_is_no_longer_a_usable_binding(): void
    {
        $firm = Firm::factory()->create();
        $fixture = $this->makeGrantedClientWithBoundRequest($firm);

        $this->runWithFirmContext($firm, fn () => $fixture['request']->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]));

        $this->expectException(NotFoundHttpException::class);

        $this->confirmDateRangeAs($fixture);
    }

    /**
     * The refusal must leave an audit trail — and that trail must carry
     * no token, account number, or other secret-shaped value (the
     * InboundWebhookAuditLogger forbidden-key discipline).
     */
    public function test_a_refused_binding_is_audited_without_any_sensitive_value(): void
    {
        $firm = Firm::factory()->create();
        $fixture = $this->makeGrantedClientWithBoundRequest($firm, connectionState: 'disconnected');

        try {
            $this->confirmDateRangeAs($fixture);
            $this->fail('A non-Active bound connection must be refused.');
        } catch (AccessDeniedHttpException) {
            // expected
        }

        $event = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', ClientPortalPlaidConnectionResolverService::DENIAL_EVENT_TYPE)
            ->latest('id')
            ->first());

        $this->assertNotNull($event, 'Every refused connection binding must be recorded through TimelineEventRecorder.');

        $metadata = $event->metadata_json;
        $this->assertSame('confirm_date_range', $metadata['action']);
        $this->assertSame('bound_connection_state_not_supported', $metadata['reason']);
        $this->assertSame($fixture['matter']->id, $metadata['matter_id']);

        foreach (['access_token', 'public_token', 'link_token', 'token', 'secret', 'account_number', 'mask', 'password', 'payload', 'body'] as $forbiddenKey) {
            $this->assertArrayNotHasKey($forbiddenKey, $metadata, "Denial audit metadata must never carry a '{$forbiddenKey}' key.");
        }

        $this->assertStringNotContainsString(
            'access-sandbox',
            json_encode($metadata, JSON_THROW_ON_ERROR),
            'No provider token material may ever appear in a denial audit record.'
        );
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * Drives the real page method with AMBIENT (non-transaction-scoped)
     * tenant context — deliberately not `runWithFirmContext()`. The
     * Client Portal's own `EstablishClientPortalTenantContext`
     * middleware establishes context for the whole request without
     * opening a transaction, and that distinction is load-bearing for
     * the denial-audit assertions below: wrapping the call in
     * `runWithFirmContext()` would open a transaction that the
     * fail-closed exception then rolls back, discarding the very audit
     * row the page just wrote (see TimelineEventRecorder's own docblock
     * on this exact hazard).
     *
     * @param  array{firm: Firm, matter: Matter, portalUser: ClientPortalUser, connection: FirmIntegration, request: FinancialEvidenceMatterRequest}  $fixture
     */
    private function confirmDateRangeAs(array $fixture): void
    {
        Auth::guard('client')->login($fixture['portalUser']);

        $tenant = new TenantContextService;
        $tenant->setFirmContext($fixture['firm']);
        $tenant->setDatabaseTenantContextForFirmId($fixture['firm']->id);

        try {
            $page = new PlaidDateRangeConfirmationPage;
            $page->matter = (string) $fixture['matter']->id;
            $page->mount();

            $page->continueToConsent();
        } finally {
            $tenant->clearDatabaseTenantContext();
            $tenant->clearFirmContext();
        }
    }

    private function liveAuthorizationFor(Firm $firm, Matter $matter): ?FinancialEvidenceMatterAuthorization
    {
        return $this->runWithFirmContext($firm, fn () => FinancialEvidenceMatterAuthorization::query()
            ->where('matter_id', $matter->id)
            ->whereNull('superseded_at')
            ->latest('id')
            ->first());
    }

    /**
     * One client, one matter, one portal grant, one request, and one
     * Plaid connection bound to that request through the
     * server-authoritative `firm_integration_id` column —
     * `PlaidAccountSelectionPage::mount()`'s own production shape.
     *
     * @return array{firm: Firm, matter: Matter, portalUser: ClientPortalUser, connection: FirmIntegration, request: FinancialEvidenceMatterRequest}
     */
    private function makeGrantedClientWithBoundRequest(Firm $firm, string $connectionState = 'active'): array
    {
        return $this->runWithFirmContext($firm, function () use ($firm, $connectionState): array {
            $client = Client::factory()->forFirm($firm)->create();
            $matter = Matter::factory()->forFirm($firm)->forClient($client)->create();

            $plaidProvider = IntegrationProvider::query()->where('code', ProviderKey::Plaid->value)->firstOrFail();

            $factory = FirmIntegration::factory()->forFirm($firm)->forProvider($plaidProvider);
            $factory = match ($connectionState) {
                'pending' => $factory->pending(),
                'disconnected' => $factory->disconnected(),
                default => $factory,
            };

            $connection = $factory->create();

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
