<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Integrations\Services\IntegrationOAuthStateService;
use App\Integrations\Support\PkceService;
use App\Integrations\Support\ProviderRedirectUrlValidator;
use App\Services\EmailBodyEncryptionService;
use App\Services\EncryptionKeyService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * OAuthStateTokenGeneratorTest — Checkpoint 5. The actual production
 * code has no separable "token generator" class (frozen-design-post-review.md
 * item 8 / agent-h-security-architecture-review.md item 7 name the raw
 * `state=` generation as a private method,
 * IntegrationOAuthStateService::generateRawState(), not a standalone
 * class) — matching Agent G's own test-plan disclaimer that the file
 * list is "whichever file plays this role," this file exercises that
 * private method directly via Reflection rather than duplicating its
 * logic or going through the full initiate() DB-backed flow (which is
 * covered end-to-end in ProviderConnectionServiceOAuthTest.php instead).
 *
 * Pure unit test: no framework boot, no database. Constructing
 * IntegrationOAuthStateService itself requires no DB access (its
 * dependencies — EmailBodyEncryptionService/EncryptionKeyService/
 * PkceService/ProviderRedirectUrlValidator — are all plain,
 * side-effect-free constructors); only invoking generateRawState() via
 * reflection is needed to exercise the generator in true isolation.
 */
final class OAuthStateTokenGeneratorTest extends TestCase
{
    private IntegrationOAuthStateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new IntegrationOAuthStateService(
            new EmailBodyEncryptionService(new EncryptionKeyService),
            new PkceService,
            new ProviderRedirectUrlValidator,
        );
    }

    private function generateRawState(): string
    {
        $method = new ReflectionMethod(IntegrationOAuthStateService::class, 'generateRawState');
        $method->setAccessible(true);

        return $method->invoke($this->service);
    }

    // ---------------------------------------------------------------
    // Entropy / uniqueness
    // ---------------------------------------------------------------

    public function test_generates_a_different_raw_state_on_every_call(): void
    {
        $this->assertNotSame($this->generateRawState(), $this->generateRawState());
    }

    public function test_produces_no_collisions_across_many_samples(): void
    {
        $samples = [];
        for ($i = 0; $i < 500; $i++) {
            $samples[] = $this->generateRawState();
        }

        $this->assertCount(500, array_unique($samples), 'Expected 500 distinct raw state tokens — a collision suggests a broken/degenerate RNG.');
    }

    /**
     * 32 raw bytes (256 bits) base64url-encoded without padding -> a
     * 43-character token, matching PkceService::generateVerifier()'s
     * own discipline exactly (frozen-design-post-review.md item 8).
     */
    public function test_raw_state_is_a_forty_three_character_url_safe_string(): void
    {
        $raw = $this->generateRawState();

        $this->assertSame(43, strlen($raw));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-_]+$/', $raw, 'Raw state must be URL-safe (base64url alphabet only, no padding).');
    }

    public function test_shows_no_fixed_prefix_across_many_samples(): void
    {
        $firstChars = [];
        for ($i = 0; $i < 200; $i++) {
            $firstChars[] = $this->generateRawState()[0];
        }

        $this->assertGreaterThan(
            1,
            count(array_unique($firstChars)),
            'A CSPRNG-backed generator must not produce a fixed first character across many samples.'
        );
    }

    // ---------------------------------------------------------------
    // Hashing
    // ---------------------------------------------------------------

    public function test_hashing_the_same_raw_state_twice_yields_the_same_hash(): void
    {
        $raw = $this->generateRawState();

        $this->assertSame(hash('sha256', $raw), hash('sha256', $raw));
    }

    public function test_different_raw_states_hash_to_different_values(): void
    {
        $rawA = $this->generateRawState();
        $rawB = $this->generateRawState();

        $this->assertNotSame(hash('sha256', $rawA), hash('sha256', $rawB));
    }

    public function test_hash_is_a_sixty_four_character_lowercase_hex_string(): void
    {
        $hash = hash('sha256', $this->generateRawState());

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    // ---------------------------------------------------------------
    // Source-inspection: CSPRNG discipline, no raw persistence
    // ---------------------------------------------------------------

    public function test_source_uses_a_csprng_backed_generator_not_a_weak_rng(): void
    {
        $source = $this->sourceOfService();

        $this->assertStringContainsString('random_bytes(', $source);
        $this->assertStringNotContainsString('mt_rand(', $source);
        $this->assertStringNotContainsString('uniqid(', $source);
        $this->assertDoesNotMatchRegularExpression('/\brand\(/', $source);
    }

    public function test_source_never_assigns_the_raw_state_to_a_persisted_column(): void
    {
        $source = $this->sourceOfService();

        // The only column this service ever writes for the state value
        // is opaque_token_hash (a sha256 digest) — never a raw 'state'
        // column. Guards against a future edit accidentally introducing
        // a queryable raw-token column (Agent H review item 7's
        // rejected Agent D design).
        $this->assertStringNotContainsString("'state' =>", $source);
        $this->assertStringNotContainsString('"state" =>', $source);
        $this->assertStringContainsString("'opaque_token_hash' => hash('sha256'", $source);
    }

    public function test_generate_raw_state_method_is_private_not_part_of_the_public_api(): void
    {
        $reflection = new ReflectionClass(IntegrationOAuthStateService::class);
        $method = $reflection->getMethod('generateRawState');

        $this->assertTrue($method->isPrivate(), 'The raw state generator must never be a public entry point — only initiate() may produce a usable state.');
    }

    private function sourceOfService(): string
    {
        $source = file_get_contents((new ReflectionClass(IntegrationOAuthStateService::class))->getFileName());
        $this->assertIsString($source);

        return $source;
    }
}
