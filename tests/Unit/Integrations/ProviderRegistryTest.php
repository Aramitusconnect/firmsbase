<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Integrations\Contracts\IntegrationProviderContract;
use App\Integrations\Contracts\SupportsApiKeyContract;
use App\Integrations\Contracts\SupportsDisconnectContract;
use App\Integrations\Contracts\SupportsHealthCheckContract;
use App\Integrations\Contracts\SupportsIncrementalSyncContract;
use App\Integrations\Contracts\SupportsOAuthContract;
use App\Integrations\Contracts\SupportsPullSyncContract;
use App\Integrations\Contracts\SupportsPushSyncContract;
use App\Integrations\Contracts\SupportsWebhooksContract;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Data\ProviderMetadata;
use App\Integrations\Enums\AuthMethod;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Exceptions\UnknownProviderException;
use App\Integrations\Providers\TestProvider\TestProvider;
use Illuminate\Support\Facades\Config;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ProviderRegistry is the framework's only container-resolution entry
 * point for providers. This test extends the full Laravel TestCase
 * (needed for app()->make()/config() resolution — pure
 * PHPUnit\Framework\TestCase cannot exercise container binding) but
 * deliberately never uses RefreshDatabase/DatabaseMigrations/factories
 * and never issues a database query: Checkpoint 1 introduces zero
 * migrations/tables, so there is nothing DB-related to test here.
 *
 * All config state is set via Config::set() (in-memory only, reset
 * automatically by Laravel's per-test application rebuild) — never a
 * real .env mutation.
 */
final class ProviderRegistryTest extends TestCase
{
    private ProviderRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new ProviderRegistry;
    }

    public function test_resolving_the_registered_test_key_returns_an_instance_implementing_the_root_contract(): void
    {
        Config::set('integrations.providers', [
            ProviderKey::Test->value => TestProvider::class,
        ]);

        $resolved = $this->registry->get(ProviderKey::Test);

        $this->assertInstanceOf(IntegrationProviderContract::class, $resolved);
        $this->assertInstanceOf(TestProvider::class, $resolved);
        $this->assertSame(ProviderKey::Test, $resolved->key());
    }

    public function test_resolving_an_unregistered_key_throws_unknown_provider_exception(): void
    {
        Config::set('integrations.providers', []);

        $this->expectException(UnknownProviderException::class);
        $this->expectExceptionMessage('Unknown integration provider key: "test".');

        $this->registry->get(ProviderKey::Test);
    }

    public function test_a_null_mapped_key_is_treated_as_unregistered_not_resolvable(): void
    {
        // Mirrors the real config/integrations.php shape: an
        // environment-gated-off provider is mapped to null, not simply
        // absent — the registry must throw for this exactly as it would
        // for a wholly-missing key, never fall back to instantiating
        // anything.
        Config::set('integrations.providers', [
            ProviderKey::Test->value => null,
        ]);

        $this->expectException(UnknownProviderException::class);

        $this->registry->get(ProviderKey::Test);
    }

    public function test_registry_source_never_hardcodes_a_reference_to_the_concrete_test_provider_class(): void
    {
        // Structural proof, not behavioral: ProviderRegistry must
        // resolve providers strictly through config('integrations.providers'),
        // never via a hardcoded fallback to a specific provider class.
        // If this file ever gained a literal `TestProvider::class` (or
        // any other concrete provider) reference, that would be a
        // regression back toward the named anti-pattern this design
        // explicitly rejects (checkpoint-00-final-specification.md §8).
        $reflection = new ReflectionClass(ProviderRegistry::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('TestProvider', $source);
        $this->assertStringNotContainsString('Providers\\', $source);
    }

    public function test_get_only_accepts_a_provider_key_enum_not_an_arbitrary_string(): void
    {
        // Structural proof that no raw-string-to-class instantiation
        // path exists at all: get()'s only parameter is strictly typed
        // to the closed ProviderKey enum, so a made-up/injected class
        // name cannot even be passed in, let alone resolved.
        $method = new ReflectionMethod(ProviderRegistry::class, 'get');
        $parameters = $method->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertNotNull($parameters[0]->getType());
        $this->assertSame(ProviderKey::class, (string) $parameters[0]->getType());
    }

    public function test_registry_resolves_exactly_whatever_class_the_config_map_names_never_a_hidden_default(): void
    {
        // Swap in a deliberately different, minimal fake class under
        // the SAME provider key to prove the registry is purely
        // config-driven — it has no hidden allowlist or hardcoded
        // fallback to the real TestProvider class.
        Config::set('integrations.providers', [
            ProviderKey::Test->value => FakeMinimalProvider::class,
        ]);

        $resolved = $this->registry->get(ProviderKey::Test);

        $this->assertInstanceOf(FakeMinimalProvider::class, $resolved);
        $this->assertNotInstanceOf(TestProvider::class, $resolved);
    }

    public function test_metadata_for_reflects_the_capabilities_the_resolved_instance_actually_implements(): void
    {
        Config::set('integrations.providers', [
            ProviderKey::Test->value => TestProvider::class,
        ]);

        $metadata = $this->registry->metadataFor(ProviderKey::Test);

        $this->assertInstanceOf(ProviderMetadata::class, $metadata);

        // Independently re-derive the expected capability list directly
        // from class_implements() in THIS test (not by calling the same
        // production helper again) so the assertion cannot pass merely
        // because both sides share one hand-maintained list.
        $knownCapabilityInterfaces = [
            SupportsOAuthContract::class,
            SupportsApiKeyContract::class,
            SupportsWebhooksContract::class,
            SupportsHealthCheckContract::class,
            SupportsPullSyncContract::class,
            SupportsPushSyncContract::class,
            SupportsIncrementalSyncContract::class,
            SupportsDisconnectContract::class,
        ];

        $implemented = class_implements(TestProvider::class) ?: [];
        $expectedCapabilities = [];
        foreach ($knownCapabilityInterfaces as $interface) {
            if (isset($implemented[$interface])) {
                $segments = explode('\\', $interface);
                $expectedCapabilities[] = end($segments);
            }
        }

        sort($expectedCapabilities);
        $actualCapabilities = $metadata->capabilities;
        sort($actualCapabilities);

        $this->assertSame($expectedCapabilities, $actualCapabilities);
        $this->assertContains('SupportsOAuthContract', $metadata->capabilities);
        $this->assertNotEmpty($metadata->capabilities);
    }

    public function test_metadata_capabilities_shrink_when_the_resolved_class_implements_fewer_interfaces(): void
    {
        // The critical "cannot silently drift" proof: register a
        // deliberately partial fake provider (implements only
        // SupportsOAuthContract) under the same key and confirm
        // metadataFor() reports ONLY that one capability — proving
        // ProviderMetadata::fromProvider() truly reflects on the
        // resolved instance rather than returning a fixed/cached list
        // tied to the provider key or a hand-maintained parallel array.
        Config::set('integrations.providers', [
            ProviderKey::Test->value => FakeOAuthOnlyProvider::class,
        ]);

        $metadata = $this->registry->metadataFor(ProviderKey::Test);

        $this->assertSame(['SupportsOAuthContract'], $metadata->capabilities);
        $this->assertNotContains('SupportsApiKeyContract', $metadata->capabilities);
        $this->assertNotContains('SupportsWebhooksContract', $metadata->capabilities);
        $this->assertNotContains('SupportsPullSyncContract', $metadata->capabilities);
    }

    public function test_all_returns_metadata_for_every_currently_registered_key(): void
    {
        Config::set('integrations.providers', [
            ProviderKey::Test->value => TestProvider::class,
        ]);

        $all = $this->registry->all();

        $this->assertCount(1, $all);
        $this->assertInstanceOf(ProviderMetadata::class, $all[0]);
        $this->assertSame(ProviderKey::Test, $all[0]->key);
    }

    public function test_all_excludes_null_mapped_environment_gated_off_keys(): void
    {
        Config::set('integrations.providers', [
            ProviderKey::Test->value => null,
        ]);

        $this->assertSame([], $this->registry->all());
    }
}

/**
 * Minimal fake provider used only to prove ProviderRegistry::get()
 * resolves strictly whatever class the config map names, with no
 * hardcoded fallback to the real TestProvider. Deliberately implements
 * nothing beyond the mandatory root contract.
 */
final class FakeMinimalProvider implements IntegrationProviderContract
{
    public function key(): ProviderKey
    {
        return ProviderKey::Test;
    }

    public function displayName(): string
    {
        return 'Fake Minimal Provider (test fixture only)';
    }

    public function description(): string
    {
        return 'Test-only fixture proving ProviderRegistry is purely config-driven.';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function supportedAuthMethods(): array
    {
        return [AuthMethod::None];
    }
}

/**
 * Fake provider implementing exactly one optional capability
 * (SupportsOAuthContract) alongside the mandatory root contract — used
 * to prove ProviderMetadata::fromProvider() capability detection
 * shrinks/grows with what the resolved class actually implements,
 * rather than being tied to the provider key or any hand-maintained
 * parallel list.
 */
final class FakeOAuthOnlyProvider implements IntegrationProviderContract, SupportsOAuthContract
{
    public function key(): ProviderKey
    {
        return ProviderKey::Test;
    }

    public function displayName(): string
    {
        return 'Fake OAuth-Only Provider (test fixture only)';
    }

    public function description(): string
    {
        return 'Test-only fixture proving ProviderMetadata reflects actual capabilities.';
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
        return 'https://fake-fixture.invalid/authorize';
    }

    public function exchangeCodeForToken(string $code, array $context): array
    {
        return ['access_token' => 'fixture-token'];
    }

    public function refreshToken(string $refreshToken, array $context = []): array
    {
        return ['access_token' => 'fixture-token'];
    }

    public function requiredScopes(array $context = []): array
    {
        return ['fixture.scope'];
    }

    public function capabilityScopeMap(): array
    {
        return [];
    }
}
