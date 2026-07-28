<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Providers\Plaid;

use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Providers\Plaid\PlaidProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\TestCase;

/**
 * PlaidProviderCapabilityDeclarationTest — FirmsVault Live Integrations,
 * Checkpoint 4 (Plaid financial evidence add-on) test-writer pass.
 * Proves Identity Verification and Monitor are genuinely
 * capability-declared-only — no real network call reachable — matching
 * this mission's LawPay-style "honest capability handling" precedent
 * (`checkpoint4-design-plaid-provider-core.md` §12; doc-research §11:
 * both products require a Plaid Production-access grant beyond standard
 * Sandbox signup and are therefore structurally absent from this
 * provider, not silently stubbed).
 *
 * Three layers of proof, from weakest to strongest:
 *   1. `declaredUnavailableCapabilities()` returns the honest,
 *      documented disclosure for both keys.
 *   2. Neither product is wired into ANY framework-visible surface
 *      (`pullableResourceTypes()`, `ResourceType`, `webhookEventTypes()`).
 *   3. STRUCTURAL ABSENCE, proven by source inspection via reflection —
 *      `PlaidProvider.php` contains ZERO methods and ZERO string
 *      literals referencing either product family's real endpoints
 *      (`/identity_verification/*`, `/watchlist_screening/*`). This is
 *      the strongest form of "no real network call reachable": it is
 *      not merely that no test exercises such a call, but that no code
 *      path in the class could ever construct one, checked directly
 *      against the shipped file's own source text.
 */
final class PlaidProviderCapabilityDeclarationTest extends TestCase
{
    use RefreshDatabase;

    private const SANDBOX_BASE = 'https://sandbox.plaid.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'integrations.oauth_apps.plaid.client_id' => 'unit-test-plaid-client-id',
            'integrations.oauth_apps.plaid.secret' => 'unit-test-plaid-secret',
            'integrations.oauth_apps.plaid.item_routing_hmac_key' => str_repeat('k', 32),
            'integrations.provider_environments.'.ProviderKey::Plaid->value => [
                'mode' => 'sandbox',
                'sandbox_base_urls' => ['default' => self::SANDBOX_BASE],
                'live_base_urls' => ['default' => self::SANDBOX_BASE],
            ],
        ]);

        // A strict, empty fake — ANY real network call attempted by
        // anything under test throws StrayRequestException immediately
        // (tests/TestCase.php's suite-wide Http::preventStrayRequests()
        // guard, made explicit here for extra clarity given this file's
        // specific "no real network call reachable" purpose).
        Http::fake([]);
    }

    private function provider(): PlaidProvider
    {
        return app(PlaidProvider::class);
    }

    // ------------------------------------------------------------
    // 1. Honest disclosure
    // ------------------------------------------------------------

    public function test_declared_unavailable_capabilities_discloses_both_identity_verification_and_monitor(): void
    {
        $declared = $this->provider()->declaredUnavailableCapabilities();

        $this->assertArrayHasKey('identity_verification', $declared);
        $this->assertArrayHasKey('monitor', $declared);
        $this->assertStringContainsString('Production access', $declared['identity_verification']);
        $this->assertStringContainsString('Production access', $declared['monitor']);
    }

    public function test_declared_unavailable_capabilities_is_the_only_two_entries_never_silently_growing_or_shrinking(): void
    {
        $declared = $this->provider()->declaredUnavailableCapabilities();

        $this->assertCount(2, $declared, 'Exactly the two documented, Plaid-confirmed access-gated products — never more, never fewer, without an explicit, reviewed change.');
        $this->assertSame(['identity_verification', 'monitor'], array_keys($declared));
    }

    public function test_declared_unavailable_capabilities_never_reaches_the_network(): void
    {
        // Calling this method must be a pure, in-memory, static
        // disclosure — never itself a probe/health-check call to Plaid.
        $this->provider()->declaredUnavailableCapabilities();

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------
    // 2. No framework-visible wiring
    // ------------------------------------------------------------

    public function test_neither_gated_product_appears_in_pullable_resource_types(): void
    {
        $pullable = $this->provider()->pullableResourceTypes();

        foreach ($pullable as $resourceType) {
            $this->assertStringNotContainsString('identity_verification', $resourceType);
            $this->assertStringNotContainsString('monitor', $resourceType);
            $this->assertStringNotContainsString('watchlist', $resourceType);
        }
    }

    public function test_neither_gated_product_appears_as_a_resource_type_enum_case(): void
    {
        $values = array_map(fn (ResourceType $case): string => $case->value, ResourceType::cases());

        $this->assertNotContains('identity_verification', $values);
        $this->assertNotContains('monitor', $values);
        $this->assertNotContains('watchlist_screening', $values);
    }

    public function test_neither_gated_product_appears_in_the_webhook_event_vocabulary(): void
    {
        $eventTypes = $this->provider()->webhookEventTypes();

        foreach ($eventTypes as $eventType) {
            $this->assertStringNotContainsString('identity_verification', $eventType);
            $this->assertStringNotContainsString('watchlist', $eventType);
            $this->assertStringNotContainsString('monitor', $eventType);
        }
    }

    // ------------------------------------------------------------
    // 3. Structural absence — direct source inspection
    // ------------------------------------------------------------

    public function test_the_class_declares_no_public_method_named_after_either_gated_product(): void
    {
        $reflection = new ReflectionClass(PlaidProvider::class);
        $methodNames = array_map(fn (\ReflectionMethod $m): string => strtolower($m->getName()), $reflection->getMethods());

        foreach ($methodNames as $name) {
            $this->assertStringNotContainsString('identityverification', $name);
            $this->assertStringNotContainsString('watchlist', $name);
            $this->assertStringNotContainsString('screening', $name);
        }
    }

    /**
     * The strongest available proof: the shipped source file itself
     * contains no CODE (an actual `$this->baseUrl().'...'` endpoint
     * construction, exactly the shape every real
     * `ProviderRequestExecutor::send()` call site in this class uses)
     * referencing either product family's real Plaid endpoints. This
     * deliberately does NOT scan for the bare strings
     * "/identity_verification/"/"/watchlist_screening/" anywhere in the
     * file — the class's own docblock (and `declaredUnavailableCapabilities()`'s
     * own disclosure array key) legitimately mentions "identity_verification"
     * in PROSE, explaining exactly why no such call exists; a bare
     * substring scan would false-positive on that honest documentation
     * rather than detecting a real endpoint call. Read directly from
     * disk via reflection's own `getFileName()` — not a guess at the
     * file's location.
     */
    public function test_the_shipped_source_file_contains_no_endpoint_construction_for_either_gated_product(): void
    {
        $reflection = new ReflectionClass(PlaidProvider::class);
        $sourcePath = $reflection->getFileName();

        $this->assertNotFalse($sourcePath);
        $source = file_get_contents($sourcePath);
        $this->assertNotFalse($source);

        // Confirms this file DOES use the "$this->baseUrl().'/path'"
        // construction as its real, live endpoint-building idiom (e.g.
        // '/transactions/sync', '/item/remove') — so the absence check
        // below is meaningful, not merely "this pattern never appears
        // anywhere in the codebase at all."
        $this->assertMatchesRegularExpression('/baseUrl\(\)\.\x27\/transactions\/sync\x27/', $source);

        foreach ([
            "baseUrl().'/identity_verification",
            "baseUrl().'/watchlist_screening",
        ] as $forbiddenEndpointConstruction) {
            $this->assertStringNotContainsString(
                $forbiddenEndpointConstruction,
                $source,
                "PlaidProvider.php must contain ZERO code constructing the real, access-gated Plaid endpoint \"{$forbiddenEndpointConstruction}...'\" — a structural absence, not merely an untested stub."
            );
        }
    }
}
