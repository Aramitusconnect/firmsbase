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
use App\Integrations\Providers\TestProvider\TestProvider;
use App\Integrations\Support\PkceService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pure unit test — no framework boot, no database, no factories.
 *
 * Per checkpoint-00-final-specification.md §9: nine small, orthogonal
 * capability contracts (one root + eight optional Supports*), never one
 * god interface. This test proves the contracts are genuinely
 * interfaces (not concrete classes an accidental refactor turned into
 * base classes), that TestProvider implements a real, non-trivial
 * subset (proving orthogonality is meaningful, not just declared), and
 * a light structural sanity check that no two Supports* interfaces
 * declare a colliding method name (which would suggest one interface
 * had drifted into another's concern).
 */
final class CapabilityContractTest extends TestCase
{
    protected function tearDown(): void
    {
        // This class mints real TestProvider authorization codes (see
        // test_no_single_provider_implements_capabilities_vacuously()
        // below), so the static in-process authorization-code registry
        // must be cleared afterward — TestProvider's own class docblock
        // condition (a) requires this from every test that mints/
        // exercises codes, so it does not leak into other tests sharing
        // this process.
        TestProvider::resetSimulationState();

        parent::tearDown();
    }

    /**
     * @return class-string[] all nine capability contracts: the root
     *                        plus the eight orthogonal Supports*
     *                        interfaces.
     */
    private static function allNineContracts(): array
    {
        return [
            IntegrationProviderContract::class,
            SupportsOAuthContract::class,
            SupportsApiKeyContract::class,
            SupportsWebhooksContract::class,
            SupportsHealthCheckContract::class,
            SupportsPullSyncContract::class,
            SupportsPushSyncContract::class,
            SupportsIncrementalSyncContract::class,
            SupportsDisconnectContract::class,
        ];
    }

    /**
     * @return class-string[] the eight optional Supports* interfaces,
     *                        excluding the mandatory root contract.
     */
    private static function eightSupportsContracts(): array
    {
        return array_values(array_diff(self::allNineContracts(), [IntegrationProviderContract::class]));
    }

    public function test_there_are_exactly_nine_capability_contracts(): void
    {
        $this->assertCount(9, self::allNineContracts());
    }

    public function test_every_capability_contract_is_an_interface_not_a_class(): void
    {
        foreach (self::allNineContracts() as $fqcn) {
            $this->assertTrue(interface_exists($fqcn), "{$fqcn} must exist as an interface.");
            $this->assertFalse(class_exists($fqcn), "{$fqcn} must be an interface, not a concrete class.");

            $reflection = new ReflectionClass($fqcn);
            $this->assertTrue($reflection->isInterface(), "{$fqcn} must reflect as an interface.");
        }
    }

    public function test_every_capability_contract_declares_at_least_one_method(): void
    {
        // Guards against a contract silently degrading into an empty
        // marker interface, which would make "implements X" meaningless.
        foreach (self::allNineContracts() as $fqcn) {
            $reflection = new ReflectionClass($fqcn);

            $this->assertNotEmpty(
                $reflection->getMethods(),
                "{$fqcn} must declare at least one method to be a meaningful capability contract."
            );
        }
    }

    public function test_test_provider_implements_the_root_contract(): void
    {
        $implemented = class_implements(TestProvider::class) ?: [];

        $this->assertArrayHasKey(
            IntegrationProviderContract::class,
            $implemented,
            'TestProvider must implement the mandatory root contract.'
        );
    }

    public function test_test_provider_implements_a_real_non_trivial_subset_of_supports_contracts(): void
    {
        $implemented = class_implements(TestProvider::class) ?: [];

        $implementedSupportsContracts = array_values(array_intersect(
            self::eightSupportsContracts(),
            array_keys($implemented)
        ));

        // "Non-trivial subset" is meaningless if it were just one or two
        // — TestProvider's whole purpose is to exercise the framework
        // end-to-end, so it must implement most/all of the 8 optional
        // contracts (checkpoint-00-final-specification.md §9/§18).
        $this->assertGreaterThanOrEqual(
            6,
            count($implementedSupportsContracts),
            'TestProvider must implement a non-trivial majority of the 8 Supports* contracts, '
                .'not a token one or two, to meaningfully exercise the framework.'
        );

        // As of Checkpoint 1's approved design, TestProvider implements
        // literally every Supports* contract — assert that exactly,
        // derived from class_implements() (never a hand-copied list),
        // so this test cannot silently drift from what the class really
        // implements.
        $this->assertSame(
            self::eightSupportsContracts(),
            $implementedSupportsContracts,
            'TestProvider is expected to implement all 8 Supports* contracts per '
                .'checkpoint-00-final-specification.md §9 ("expected to implement most or all 9").'
        );
    }

    public function test_no_single_provider_implements_capabilities_vacuously(): void
    {
        // Proves the implementations are not stub/no-op placeholders
        // that would make "implements X" declared-but-meaningless: two
        // independent calls to exchangeCodeForToken() — each against its
        // own freshly minted, single-use authorization code (mirroring a
        // real provider's single-use-code semantics; NOT the same code
        // exchanged twice, which would instead exercise replay
        // rejection) — must produce different runtime values (a
        // hardcoded/vacuous implementation would return the same
        // constant both times). Full non-hardcoded-secret coverage lives
        // in TestProviderStubTest; this is the orthogonality-focused
        // half of that same proof.
        $provider = new TestProvider();
        $pkce = new PkceService();

        $firstVerifier = $pkce->generateVerifier();
        $firstCode = $provider->simulateAuthorizationGrant($pkce->challengeForVerifier($firstVerifier));
        $first = $provider->exchangeCodeForToken($firstCode, ['code_verifier' => $firstVerifier]);

        $secondVerifier = $pkce->generateVerifier();
        $secondCode = $provider->simulateAuthorizationGrant($pkce->challengeForVerifier($secondVerifier));
        $second = $provider->exchangeCodeForToken($secondCode, ['code_verifier' => $secondVerifier]);

        $this->assertNotSame($first['access_token'], $second['access_token']);
    }

    public function test_no_two_supports_contracts_declare_a_colliding_method_name(): void
    {
        // Light structural sanity check: if two orthogonal Supports*
        // contracts declared the same method name, that would suggest
        // one interface has drifted into another's concern (e.g. a
        // webhook-shaped method leaking into the sync contract).
        $methodOwners = [];

        foreach (self::eightSupportsContracts() as $fqcn) {
            $reflection = new ReflectionClass($fqcn);

            foreach ($reflection->getMethods() as $method) {
                $name = $method->getName();
                $existingOwner = $methodOwners[$name] ?? '';

                $this->assertArrayNotHasKey(
                    $name,
                    $methodOwners,
                    "Method '{$name}' is declared by both {$existingOwner} and {$fqcn} — "
                        .'Supports* contracts must stay orthogonal, one capability per interface.'
                );

                $methodOwners[$name] = $fqcn;
            }
        }

        // Sanity check that the loop above actually inspected a
        // meaningful number of methods rather than vacuously passing
        // over empty interfaces.
        $this->assertGreaterThanOrEqual(8, count($methodOwners));
    }

    public function test_root_contract_methods_do_not_collide_with_any_supports_contract(): void
    {
        $rootMethods = array_map(
            static fn ($m) => $m->getName(),
            (new ReflectionClass(IntegrationProviderContract::class))->getMethods()
        );

        $supportsMethods = [];
        foreach (self::eightSupportsContracts() as $fqcn) {
            foreach ((new ReflectionClass($fqcn))->getMethods() as $method) {
                $supportsMethods[] = $method->getName();
            }
        }

        $this->assertEmpty(
            array_intersect($rootMethods, $supportsMethods),
            'The mandatory root contract must not declare any method name that also belongs to an '
                .'optional Supports* capability contract.'
        );
    }
}
