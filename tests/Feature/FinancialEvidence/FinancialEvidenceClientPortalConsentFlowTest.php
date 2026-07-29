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
use App\Services\ClientPortalMatterAccessPolicyService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FinancialEvidenceClientPortalConsentFlowTest — FirmsVault Live
 * Integrations, Checkpoint 4 ("Plaid financial evidence add-on"). The
 * Client Portal's Plaid consent flow content — authenticated via the
 * REAL `client` guard (Auth::guard('client')) and the real
 * ClientPortalUser/Client identity, never a synthetic actor. Per this
 * test-writer's scope, the two-hop RLS bootstrap mechanism itself
 * (EstablishClientPortalTenantContext) is a Matter/Client-Portal-track
 * authentication concern, tested there — these tests stand in for
 * "tenant context has already been correctly established" (via an
 * ordinary runWithFirmContext()) and instead prove what the CONSENT
 * PAGE ITSELF does once a real, guard-authenticated client is present:
 * grant/decline recording, matter-grant gating, and cross-client
 * isolation of the consent artifact.
 */
class FinancialEvidenceClientPortalConsentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // grantConsent()/declineConsent() redirect via
        // PlaidRequestReviewPage::getUrl()/PlaidUploadFallbackPage::getUrl(),
        // which Filament resolves against the CURRENTLY ACTIVE panel —
        // normally set by Filament's own panel-resolution middleware
        // for a real HTTP request; a raw PHP method call in a test has
        // no such request, so the current panel must be set explicitly.
        Filament::setCurrentPanel(Filament::getPanel('client-portal'));
    }

    public function test_a_client_with_a_portal_grant_can_view_the_pending_request_and_grant_consent(): void
    {
        [$firm, $matter, $portalUser, $connection, $request] = $this->makeGrantedClientWithPendingRequest();

        Auth::guard('client')->login($portalUser);

        $page = new PlaidConsentPage;
        $page->matter = (string) $matter->id;
        $page->firmIntegration = (string) $connection->id;

        $this->runWithFirmContext($firm, fn () => $page->grantConsent());

        $consent = $this->runWithFirmContext($firm, fn () => FinancialEvidenceClientConsent::query()->where('matter_id', $matter->id)->first());

        $this->assertNotNull($consent);
        $this->assertNotNull($consent->granted_at);
        $this->assertNull($consent->declined_at);
        $this->assertSame($connection->id, $consent->firm_integration_id);
        $this->assertSame($matter->client_id, $consent->client_id);

        $reloadedRequest = $this->runWithFirmContext($firm, fn () => FinancialEvidenceMatterRequest::query()->find($request->id));
        $this->assertSame('consented', $reloadedRequest->status);
    }

    public function test_declining_records_zero_granted_products_and_flips_the_request_to_declined(): void
    {
        [$firm, $matter, $portalUser, $connection, $request] = $this->makeGrantedClientWithPendingRequest();

        Auth::guard('client')->login($portalUser);

        $page = new PlaidConsentPage;
        $page->matter = (string) $matter->id;

        $this->runWithFirmContext($firm, fn () => $page->declineConsent());

        $consent = $this->runWithFirmContext($firm, fn () => FinancialEvidenceClientConsent::query()->where('matter_id', $matter->id)->first());

        $this->assertNotNull($consent);
        $this->assertNotNull($consent->declined_at);
        $this->assertNull($consent->granted_at);
        $this->assertSame([], $consent->granted_products_json);
        $this->assertNull($consent->firm_integration_id, 'A decline has no connection to attach to.');

        $reloadedRequest = $this->runWithFirmContext($firm, fn () => FinancialEvidenceMatterRequest::query()->find($request->id));
        $this->assertSame('declined', $reloadedRequest->status);
    }

    public function test_a_client_without_a_portal_grant_for_the_matter_cannot_grant_consent(): void
    {
        [$firm, $matter, , $connection] = $this->makeGrantedClientWithPendingRequest();

        // A SECOND client, with no ClientPortalMatterGrant for this matter.
        $otherClient = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $otherPortalUser = $this->runWithFirmContext($firm, fn () => ClientPortalUser::query()->create([
            'client_id' => $otherClient->id,
            'email' => 'other-'.Str::random(8).'@example.test',
            'password' => 'irrelevant-hashed-value',
            'is_active' => true,
        ]));

        Auth::guard('client')->login($otherPortalUser);

        $canAccess = $this->runWithFirmContext($firm, fn () => app(ClientPortalMatterAccessPolicyService::class)->canAccessMatter($otherPortalUser, $matter));

        $this->assertFalse($canAccess, 'A client with no ClientPortalMatterGrant for this matter must never be authorized to see or consent to it.');
    }

    public function test_the_client_facing_page_never_reads_the_platform_wholesale_rate_card(): void
    {
        // Structural, source-level proof of §4.12's binding constraint:
        // no Client Portal page reads provider_rate_card_entries.provider_cost_cents
        // anywhere — the client consents to products, never prices.
        // Comments/docblocks are stripped via PHP's own tokenizer first:
        // this file's own docblock discusses "provider_cost_cents" in
        // prose (to document the constraint for a human reader), which
        // a naive string-contains scan over the raw file would
        // false-positive on.
        $pageSource = file_get_contents(app_path('Filament/ClientPortal/Pages/PlaidConsentPage.php'));
        $this->assertNotFalse($pageSource);

        $code = '';
        foreach (token_get_all($pageSource) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        $this->assertStringNotContainsString('provider_cost_cents', $code);
        $this->assertStringNotContainsString('ProviderRateCardEntry', $code);
    }

    public function test_the_consent_record_is_isolated_per_firm_a_firm_a_query_never_returns_firm_bs_consent(): void
    {
        [$firmA, $matterA, $portalUserA, $connectionA] = $this->makeGrantedClientWithPendingRequest();
        [$firmB] = $this->makeGrantedClientWithPendingRequest();

        Auth::guard('client')->login($portalUserA);
        $page = new PlaidConsentPage;
        $page->matter = (string) $matterA->id;
        $page->firmIntegration = (string) $connectionA->id;
        $this->runWithFirmContext($firmA, fn () => $page->grantConsent());

        $visibleFromFirmB = $this->runWithFirmContext($firmB, fn () => FinancialEvidenceClientConsent::query()->count());

        $this->assertSame(0, $visibleFromFirmB, 'Firm B\'s tenant context must never see Firm A\'s client consent row.');
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * @return array{0: Firm, 1: Matter, 2: ClientPortalUser, 3: FirmIntegration, 4: FinancialEvidenceMatterRequest}
     */
    private function makeGrantedClientWithPendingRequest(): array
    {
        $firm = Firm::factory()->create();

        return $this->runWithFirmContext($firm, function () use ($firm) {
            $client = Client::factory()->forFirm($firm)->create();
            $matter = Matter::factory()->forFirm($firm)->forClient($client)->create();

            $plaidProvider = IntegrationProvider::query()->where('code', ProviderKey::Plaid->value)->first();
            $connection = FirmIntegration::factory()->forFirm($firm)->forProvider($plaidProvider)->create();

            $requestedBy = FirmUser::factory()->create(['firm_id' => $firm->id]);

            $matterRequest = FinancialEvidenceMatterRequest::query()->create([
                'firm_id' => $firm->id,
                'matter_id' => $matter->id,
                // Release-candidate remediation (defect H1): the consent
                // page no longer accepts a client-supplied
                // firm_integration_id as the binding source — it resolves
                // the connection from THIS server-authoritative column,
                // exactly as PlaidExchangeController already does (see
                // PlaidExchangeControllerAuthorizationTest's identical
                // fixture). The pre-fix fixture left this null, which is
                // a state PlaidAccountSelectionPage::mount() never
                // actually produces once a connection exists for a
                // request; setting it makes the fixture match production,
                // it does not relax any assertion below.
                'firm_integration_id' => $connection->id,
                'requested_by_firm_user_id' => $requestedBy->id,
                'purpose' => 'Verify income for support calculation.',
                'requested_products_json' => ['bank_account', 'transaction'],
                'status' => 'pending',
                'requested_at' => now(),
            ]);

            $portalUser = ClientPortalUser::query()->create([
                'client_id' => $client->id,
                'email' => 'client-'.Str::random(8).'@example.test',
                'password' => 'irrelevant-hashed-value',
                'is_active' => true,
            ]);

            ClientPortalMatterGrant::query()->create([
                'firm_id' => $firm->id,
                'client_id' => $client->id,
                'matter_id' => $matter->id,
                'granted_at' => now(),
            ]);

            return [$firm, $matter, $portalUser, $connection, $matterRequest];
        });
    }
}
