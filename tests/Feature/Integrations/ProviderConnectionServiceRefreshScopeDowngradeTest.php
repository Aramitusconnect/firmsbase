<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Contracts\IntegrationProviderContract;
use App\Integrations\Contracts\SupportsOAuthContract;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\AuthMethod;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
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
use App\Models\TimelineEvent;
use App\Services\EmailBodyEncryptionService;
use App\Services\EncryptionKeyService;
use App\Services\IntegrationEntitlementPolicyService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * ProviderConnectionServiceRefreshScopeDowngradeTest — Checkpoint 1
 * (FirmsVault Live Integrations),
 * checkpoint1-design-oauth-security-review.md §8;
 * checkpoint1-security-review.md Finding 8. Proves the token-*refresh*
 * path's scope-downgrade detection: a refresh response carrying a
 * narrower `scope` than requiredScopes() must demote the connection to
 * ScopeInsufficient and fire `integration_oauth.scope_downgrade_detected_on_refresh`;
 * an unchanged/sufficient scope must NOT transition status. Also proves
 * the Finding 8 code-quality nit fix — `($outcome['refreshedScopes'] ??
 * null) !== null` — by exercising the 'already_fresh' outcome branch
 * directly (the branch that never sets the 'refreshedScopes' key at
 * all): a regression back to a bare `!== null` would throw an
 * "undefined array key" warning (converted to an ErrorException under
 * this app's default error-to-exception handling) on every no-op
 * refresh.
 *
 * Uses a minimal fake OAuth provider (not genuine TestProvider, whose
 * refreshToken() always returns the FULL requiredScopes() grant with no
 * way to configure a narrower one) so the exact `scope` field on the
 * refresh response can be controlled directly.
 */
final class ProviderConnectionServiceRefreshScopeDowngradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_narrower_refresh_scope_demotes_the_connection_to_scope_insufficient_and_fires_the_downgrade_event(): void
    {
        $requiredScopes = ['test.read', 'test.write'];
        [$firm, $connection] = $this->readyForRefreshConnection($requiredScopes, $requiredScopes);

        $this->registerFakeOAuthProvider($requiredScopes, 'test.read'); // narrower than required

        $result = $this->service()->refreshConnectionToken($connection->fresh());

        $this->assertSame(ConnectionStatus::ScopeInsufficient, $result->status);

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame(ConnectionStatus::ScopeInsufficient, $fresh->status);

        $event = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()
            ->where('event_type', 'integration_oauth.scope_downgrade_detected_on_refresh')
            ->latest('id')
            ->first());

        $this->assertNotNull($event);
        $this->assertSame($connection->id, $event->metadata_json['firm_integration_id']);
        $this->assertSame($requiredScopes, $event->metadata_json['required_scopes']);
        $this->assertSame(['test.read'], $event->metadata_json['refreshed_scopes']);
        $this->assertFalse($event->metadata_json['still_satisfied']);
    }

    public function test_an_unchanged_sufficient_refresh_scope_does_not_transition_status_or_fire_the_downgrade_event(): void
    {
        $requiredScopes = ['test.read', 'test.write'];
        [$firm, $connection] = $this->readyForRefreshConnection($requiredScopes, $requiredScopes);

        // Refresh returns exactly the same scopes already stored —
        // sufficient AND unchanged.
        $this->registerFakeOAuthProvider($requiredScopes, 'test.read test.write');

        $result = $this->service()->refreshConnectionToken($connection->fresh());

        $this->assertSame(ConnectionStatus::Active, $result->status);

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame(ConnectionStatus::Active, $fresh->status, 'A sufficient, unchanged scope grant must never transition the connection away from Active.');

        $event = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()
            ->where('event_type', 'integration_oauth.scope_downgrade_detected_on_refresh')
            ->first());

        $this->assertNull($event, 'No downgrade event may fire when the refreshed scope grant is unchanged and still sufficient.');
    }

    /**
     * Regression guard for Finding 8's code-quality nit: the
     * 'already_fresh' outcome branch never sets a 'refreshedScopes' key
     * on its returned array at all — accessing it with a bare `!== null`
     * (rather than `?? null !== null`) would trip an undefined-array-key
     * warning on EVERY no-op refresh. This test forces the
     * double-checked-locking guard to short-circuit into that exact
     * branch (access credential comfortably not-yet-expired) and simply
     * asserts the call completes without throwing.
     */
    public function test_the_already_fresh_outcome_branch_completes_without_an_undefined_array_key_warning(): void
    {
        $requiredScopes = ['test.read', 'test.write'];
        [$firm, $connection] = $this->readyForRefreshConnection($requiredScopes, $requiredScopes, accessCredentialExpiresAt: now()->addHours(2));

        $this->registerFakeOAuthProvider($requiredScopes, 'test.read'); // would demote IF the refresh actually ran

        $result = $this->service()->refreshConnectionToken($connection->fresh());

        $this->assertTrue($result->successful);

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame(
            ConnectionStatus::Active,
            $fresh->status,
            'The already-fresh no-op path must never call the provider at all, so the narrower scope configured on the fake provider above must never be observed.'
        );
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
        );
    }

    private function credentialService(): IntegrationCredentialService
    {
        return new IntegrationCredentialService(new EmailBodyEncryptionService(new EncryptionKeyService), new TimelineEventRecorder);
    }

    /**
     * @param  string[]  $requiredScopes
     * @param  string[]  $storedGrantedScopes
     * @return array{0: Firm, 1: FirmIntegration}
     */
    private function readyForRefreshConnection(
        array $requiredScopes,
        array $storedGrantedScopes,
        ?Carbon $accessCredentialExpiresAt = null,
    ): array {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        $provider = IntegrationProvider::query()->where('code', ProviderKey::Test->value)->first()
            ?? IntegrationProvider::factory()->create(['code' => ProviderKey::Test->value]);

        $connection = FirmIntegration::factory()->forFirm($firm)->forProvider($provider)->create([
            'status' => ConnectionStatus::Active->value,
            'external_account_id' => null,
            'scopes_granted_json' => $storedGrantedScopes,
        ]);

        // Access credential: expired by default so the double-checked-
        // locking guard does NOT short-circuit into 'already_fresh' —
        // callers that need the opposite pass accessCredentialExpiresAt
        // comfortably in the future.
        $this->runWithFirmContext($firm, fn () => $this->credentialService()->store(
            $connection,
            CredentialType::OauthAccessToken,
            'access-token-plaintext-'.Str::random(16),
            expiresAt: $accessCredentialExpiresAt ?? now()->subMinute(),
        ));

        $this->runWithFirmContext($firm, fn () => $this->credentialService()->store(
            $connection,
            CredentialType::OauthRefreshToken,
            'refresh-token-plaintext-'.Str::random(16),
        ));

        return [$firm, $connection];
    }

    /**
     * @param  string[]  $requiredScopes
     */
    private function registerFakeOAuthProvider(array $requiredScopes, ?string $refreshResponseScope): void
    {
        $provider = new class($requiredScopes, $refreshResponseScope) implements IntegrationProviderContract, SupportsOAuthContract
        {
            public function __construct(
                private readonly array $requiredScopes,
                private readonly ?string $refreshResponseScope,
            ) {}

            public function key(): ProviderKey
            {
                return ProviderKey::Test;
            }

            public function displayName(): string
            {
                return 'Fake Scope-Aware OAuth Provider';
            }

            public function description(): string
            {
                return 'Deterministic test fixture provider for refresh-path scope-downgrade proof.';
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
                throw new RuntimeException('exchangeCodeForToken() is not exercised by this test fixture.');
            }

            public function refreshToken(string $refreshToken, array $context = []): array
            {
                $tokenSet = [
                    'access_token' => Str::random(40),
                    'refresh_token' => Str::random(40),
                    'token_type' => 'bearer',
                    'expires_in' => 3600,
                ];

                if ($this->refreshResponseScope !== null) {
                    $tokenSet['scope'] = $this->refreshResponseScope;
                }

                return $tokenSet;
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
}
