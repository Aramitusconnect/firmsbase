<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Integrations\Contracts\IntegrationProviderContract;
use App\Integrations\Contracts\SupportsOAuthContract;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Data\OAuthInitiationResult;
use App\Integrations\Enums\AuthMethod;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Exceptions\OAuthTenantMismatchException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Integrations\Services\IntegrationCredentialService;
use App\Integrations\Services\IntegrationOAuthStateService;
use App\Integrations\Services\ProviderConnectionService;
use App\Integrations\Support\GmailMailboxRoutingService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Integrations\Support\PkceService;
use App\Integrations\Support\PlaidItemRoutingService;
use App\Integrations\Support\ProviderRedirectUrlValidator;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TenantEncryptionKey;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\EmailBodyEncryptionService;
use App\Services\EncryptionKeyService;
use App\Services\EntitlementService;
use App\Services\IntegrationEntitlementPolicyService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ProviderConnectionServiceTenantMismatchTest — Checkpoint 2 (FirmsVault
 * Live Integrations) test-writing pass. Proves
 * ProviderConnectionService::finishCallback()'s `external_tenant_id`
 * capture/mismatch logic (checkpoint2-combined-design.md §2 P-6d;
 * checkpoint2-security-review.md Finding 1, confirmed sound) — the
 * SAME capture-if-null / hash_equals()-compare-and-reject-if-both-set
 * shape as the already-audited `external_account_id` check, applied to
 * a second, coarser-grained column.
 *
 * Uses a minimal fake OAuth provider (mirroring
 * ProviderConnectionServiceRefreshScopeDowngradeTest::registerFakeOAuthProvider()'s
 * established pattern for this file) rather than a real
 * Microsoft365Provider HTTP round-trip — the tenant-mismatch guard lives
 * entirely in ProviderConnectionService, not in any specific provider,
 * so a deterministic fake that returns a directly-controllable tenant_id
 * is the correct, minimal fixture; no Http::fake() network stubbing is
 * needed since this fake never calls ProviderRequestExecutor::send() at
 * all (Http::fake() with no rules is still registered defensively so
 * the suite-wide Http::preventStrayRequests() guard has nothing to catch
 * either way).
 */
final class ProviderConnectionServiceTenantMismatchTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, int> firm_id => TenantEncryptionKey id */
    private array $encryptionKeyIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // See ProviderConnectionServiceOAuthTest::setUp()'s identical
        // comment for why BOTH forceRootUrl() and forceScheme() are
        // required together for route(..., absolute: true) to resolve
        // to a redirect_uri ProviderRedirectUrlValidator::assertSafe()
        // actually accepts, outside of a simulated HTTP request.
        config(['app.url' => 'https://app.firmsbase.test']);
        URL::forceRootUrl('https://app.firmsbase.test');
        URL::forceScheme('https');

        Http::fake();
    }

    // ------------------------------------------------------------
    // First connect: capture, no mismatch check applied
    // ------------------------------------------------------------

    public function test_first_connect_captures_external_tenant_id_with_no_mismatch_check_applied(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->registerFakeTenantAwareProvider('acct-1', 'tenant-A');

        $flow = $this->initiateFlow($connection, $firmUser);
        $result = $this->service()->completeOAuthCallback($flow['rawState'], Str::random(20), $firmUser->user_id);

        $this->assertTrue($result->successful);
        $this->assertSame(ConnectionStatus::Active, $result->status);

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame('tenant-A', $fresh->external_tenant_id, 'First connect must capture the returned tenant_id — nothing to compare against yet.');
    }

    // ------------------------------------------------------------
    // Reconnect with the SAME tenant_id: succeeds normally
    // ------------------------------------------------------------

    public function test_reconnect_with_the_same_tenant_id_succeeds_normally(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->registerFakeTenantAwareProvider('acct-1', 'tenant-A');
        $firstFlow = $this->initiateFlow($connection, $firmUser);
        $this->service()->completeOAuthCallback($firstFlow['rawState'], Str::random(20), $firmUser->user_id);

        $connection = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        // Same account, same tenant — a genuine, unremarkable
        // reauthorization.
        $this->registerFakeTenantAwareProvider('acct-1', 'tenant-A');
        $reauthFlow = $this->initiateFlow($connection, $firmUser);

        $result = $this->service()->completeOAuthCallback($reauthFlow['rawState'], Str::random(20), $firmUser->user_id);

        $this->assertTrue($result->successful);
        $this->assertSame(ConnectionStatus::Active, $result->status);

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame('tenant-A', $fresh->external_tenant_id, 'A matching reauthorization must leave the pinned tenant unchanged.');
    }

    // ------------------------------------------------------------
    // Reconnect with a DIFFERENT tenant_id: Error, throws, fires the
    // audit event — verified via a real TimelineEvent query.
    // ------------------------------------------------------------

    /**
     * FIXED — this test originally documented a genuine, real,
     * provider-agnostic production defect found during Checkpoint 2
     * test-writing (2026-07-27): the account-mismatch and
     * tenant-mismatch rejection branches in
     * ProviderConnectionService::finishCallback() used to write an
     * Error-status transition + audit event immediately before
     * throwing — but since finishCallback() runs entirely inside
     * completeOAuthCallback()'s TenantContextService::runWithFirmContext()/
     * DB::transaction() wrap, that throw rolled back BOTH writes,
     * silently discarding the Error transition and the audit event.
     *
     * Fixed properly (not merely by adding
     * TimelineEventRecorder::record()'s $independentOfAmbientTransaction
     * flag, which was the first attempt and turned out to deadlock: the
     * SAME row is already lockForUpdate()-locked by the still-open
     * ambient transaction, so a write against it from a separate
     * connection blocks until that transaction ends — which it can't,
     * since finishCallback() itself hasn't returned yet). The real fix:
     * both mismatch branches now throw immediately, with NO write at
     * all inside finishCallback() — completeOAuthCallback() itself
     * catches OAuthAccountMismatchException/OAuthTenantMismatchException
     * AFTER runWithFirmContext()'s transaction has already rolled back
     * and released the lock, and records the durable Error-transition +
     * audit event as a fresh, ordinary, second operation at that point
     * (see ProviderConnectionService::recordMismatchRejectionAfterRollback()'s
     * own docblock for the full history). This test now asserts the
     * genuinely-fixed, correct behavior and passes.
     */
    public function test_reconnect_with_a_different_tenant_id_transitions_to_error_throws_and_fires_the_mismatch_audit_event(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->registerFakeTenantAwareProvider('acct-1', 'tenant-A');
        $firstFlow = $this->initiateFlow($connection, $firmUser);
        $this->service()->completeOAuthCallback($firstFlow['rawState'], Str::random(20), $firmUser->user_id);

        $connection = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        // Same account, but a DIFFERENT tenant — exactly the threat this
        // guard exists to close: a reconnect silently re-pointing a
        // firm's connection at a different provider tenant.
        $this->registerFakeTenantAwareProvider('acct-1', 'tenant-B');
        $reauthFlow = $this->initiateFlow($connection, $firmUser);

        try {
            $this->service()->completeOAuthCallback($reauthFlow['rawState'], Str::random(20), $firmUser->user_id);
            $this->fail('Expected an OAuthTenantMismatchException for a reconnect presenting a different provider tenant.');
        } catch (OAuthTenantMismatchException $e) {
            // Expected — and confirm the exception message never embeds
            // either raw tenant identifier.
            $this->assertStringNotContainsString('tenant-A', $e->getMessage());
            $this->assertStringNotContainsString('tenant-B', $e->getMessage());
        }

        // The connection must transition to Error on a genuine tenant
        // mismatch (checkpoint2-combined-design.md §2 P-6d).
        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame(ConnectionStatus::Error, $fresh->status);

        // Exact event name verified via a real TimelineEvent query — not
        // merely "no exception".
        $event = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()
            ->where('event_type', 'integration_oauth.provider_tenant_mismatch')
            ->where('subject_type', FirmIntegration::class)
            ->where('subject_id', $connection->id)
            ->latest('id')
            ->first());

        $this->assertNotNull($event, 'Expected an integration_oauth.provider_tenant_mismatch audit event to have been recorded.');
        $this->assertSame($connection->id, $event->metadata_json['firm_integration_id'] ?? null);

        // The audit event's metadata must never embed either raw tenant
        // identifier either (this domain's standing "no raw identifier
        // in an audit event" discipline).
        $metadataJson = json_encode($event->metadata_json);
        $this->assertIsString($metadataJson);
        $this->assertStringNotContainsString('tenant-A', $metadataJson);
        $this->assertStringNotContainsString('tenant-B', $metadataJson);
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function service(): ProviderConnectionService
    {
        return new ProviderConnectionService(
            new IntegrationOAuthStateService(
                new EmailBodyEncryptionService(new EncryptionKeyService),
                new PkceService,
                new ProviderRedirectUrlValidator,
            ),
            new IntegrationCredentialService(new EmailBodyEncryptionService(new EncryptionKeyService), new TimelineEventRecorder),
            new IntegrationAccessPolicyService(new TimelineEventRecorder),
            new ProviderRegistry,
            new OutboundProviderHttpClient,
            new ProviderRedirectUrlValidator,
            new TimelineEventRecorder,
            app(IntegrationEntitlementPolicyService::class),
            // Checkpoint 3 addition (FirmsVault Live Integrations,
            // Google Workspace): ProviderConnectionService's constructor
            // gained this 9th, required dependency -- every manual
            // construction site in this file must supply it.
            app(GmailMailboxRoutingService::class),
            // Checkpoint 4 addition (FirmsVault Live Integrations, Plaid
            // financial evidence add-on): ProviderConnectionService's
            // constructor gained this 10th, required dependency -- every
            // manual construction site in this file must supply it.
            app(PlaidItemRoutingService::class),
        );
    }

    /**
     * A minimal, deterministic fake OAuth provider whose
     * exchangeCodeForToken() returns a directly-controllable
     * external_account_id/tenant_id pair — mirrors
     * ProviderConnectionServiceRefreshScopeDowngradeTest::registerFakeOAuthProvider()'s
     * established shape for this test file, adapted to the
     * tenant-mismatch surface instead of the refresh-scope-downgrade
     * surface. Registered under ProviderKey::Test so
     * FirmIntegrationFactory's default provider resolution (code='test')
     * keeps working unchanged.
     */
    private function registerFakeTenantAwareProvider(?string $externalAccountId, ?string $tenantId): void
    {
        $requiredScopes = ['test.read', 'test.write'];

        $provider = new class($externalAccountId, $tenantId, $requiredScopes) implements IntegrationProviderContract, SupportsOAuthContract
        {
            public function __construct(
                private readonly ?string $externalAccountId,
                private readonly ?string $tenantId,
                private readonly array $requiredScopes,
            ) {}

            public function key(): ProviderKey
            {
                return ProviderKey::Test;
            }

            public function displayName(): string
            {
                return 'Fake Tenant-Aware OAuth Provider';
            }

            public function description(): string
            {
                return 'Deterministic test fixture provider — returns a directly-controllable external_account_id/tenant_id pair for tenant-mismatch proof.';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function supportedAuthMethods(): array
            {
                return [AuthMethod::OAuth2];
            }

            public function authorizationUrl(array $params): string
            {
                return 'https://fake-oauth-provider.invalid/authorize?'.http_build_query($params);
            }

            public function exchangeCodeForToken(string $code, array $context): array
            {
                return [
                    'access_token' => Str::random(40),
                    'refresh_token' => Str::random(40),
                    'token_type' => 'bearer',
                    'expires_in' => 3600,
                    'scope' => implode(' ', $this->requiredScopes),
                    'external_account_id' => $this->externalAccountId,
                    'tenant_id' => $this->tenantId,
                ];
            }

            public function refreshToken(string $refreshToken, array $context = []): array
            {
                throw new \RuntimeException('refreshToken() is not exercised by this test fixture.');
            }

            public function requiredScopes(array $context = []): array
            {
                return $this->requiredScopes;
            }

            public function capabilityScopeMap(): array
            {
                return [];
            }
        };

        $class = get_class($provider);
        app()->instance($class, $provider);
        config(['integrations.providers' => [ProviderKey::Test->value => $class]]);
    }

    private function firmWithActiveKey(): Firm
    {
        $firm = Firm::factory()->create();
        $key = $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());
        $this->encryptionKeyIds[$firm->id] = $key->id;

        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);

        return $firm;
    }

    private function firmUserFor(Firm $firm, FirmUserRole $role): FirmUser
    {
        $user = User::factory()->create();

        return $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->forUser($user)->role($role)->create());
    }

    /**
     * @return array{0: Firm, 1: FirmIntegration, 2: FirmUser}
     */
    private function firmConnectionAndActor(FirmUserRole $role = FirmUserRole::Attorney): array
    {
        $firm = $this->firmWithActiveKey();
        // external_account_id forced to null — see
        // ProviderConnectionServiceOAuthTest::firmConnectionAndActor()'s
        // identical comment: this fixture represents a connection that
        // has never completed a real OAuth exchange yet, and the
        // factory's own random default would otherwise spuriously
        // collide with this test's OWN externalAccountId fixtures.
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->pending()->create(['external_account_id' => null]));
        $firmUser = $this->firmUserFor($firm, $role);

        return [$firm, $connection, $firmUser];
    }

    /**
     * @return array{result: OAuthInitiationResult, rawState: string}
     */
    private function initiateFlow(FirmIntegration $connection, FirmUser $firmUser): array
    {
        $redirectUri = route('integrations.oauth.callback', [], true);
        $result = $this->service()->initiateOAuthConnection($connection, $firmUser->user_id, $redirectUri);

        $query = [];
        parse_str((string) parse_url($result->authorizationUrl, PHP_URL_QUERY), $query);

        return [
            'result' => $result,
            'rawState' => $query['state'],
        ];
    }
}
