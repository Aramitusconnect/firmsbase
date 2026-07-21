<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Integrations\Support\PkceService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * PkceServiceTest — Checkpoint 5 (frozen-design-post-review.md item 12;
 * agent-h-security-architecture-review.md item 8). Pure unit test: no
 * framework boot, no database — PkceService is pure, in-memory string
 * generation/derivation/comparison with zero external dependencies.
 *
 * S256 is the ONLY challenge method this class (or anything calling
 * it) is permitted to use — there is no "plain" method anywhere in the
 * production surface, and this file asserts that structurally, not
 * just behaviorally.
 */
final class PkceServiceTest extends TestCase
{
    private PkceService $pkce;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pkce = new PkceService();
    }

    // ---------------------------------------------------------------
    // Verifier generation / entropy
    // ---------------------------------------------------------------

    public function test_generate_verifier_produces_a_different_value_on_every_call(): void
    {
        $first = $this->pkce->generateVerifier();
        $second = $this->pkce->generateVerifier();

        $this->assertNotSame($first, $second);
    }

    public function test_generate_verifier_produces_no_collisions_across_many_samples(): void
    {
        $samples = [];
        for ($i = 0; $i < 500; $i++) {
            $samples[] = $this->pkce->generateVerifier();
        }

        $this->assertCount(500, array_unique($samples), 'Expected 500 distinct verifiers — a collision suggests a broken/degenerate RNG.');
    }

    /**
     * 32 raw bytes base64url-encoded without padding -> 43 characters,
     * comfortably inside RFC 7636's required 43-128 character range and
     * implying >=256 bits of entropy.
     */
    public function test_generate_verifier_produces_a_forty_three_character_string(): void
    {
        $verifier = $this->pkce->generateVerifier();

        $this->assertSame(43, strlen($verifier));
    }

    public function test_generate_verifier_uses_only_the_rfc7636_unreserved_character_set(): void
    {
        $verifier = $this->pkce->generateVerifier();

        // RFC 7636 §4.1: [A-Z] / [a-z] / [0-9] / "-" / "." / "_" / "~".
        // This class's base64url alphabet is a subset of that set
        // (never uses "." or "~", but never anything outside it either).
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-_]+$/', $verifier);
    }

    public function test_generate_verifier_shows_no_fixed_prefix_across_many_samples(): void
    {
        $firstChars = [];
        for ($i = 0; $i < 200; $i++) {
            $firstChars[] = $this->pkce->generateVerifier()[0];
        }

        $this->assertGreaterThan(
            1,
            count(array_unique($firstChars)),
            'A CSPRNG-backed generator must not produce a fixed first character across many samples.'
        );
    }

    /**
     * Behavioral CSPRNG proof, not a source-text scan: decodes many
     * real generateVerifier() outputs back to their raw bytes and
     * checks the byte distribution actually produced at runtime looks
     * close to uniform. A weak generator (mt_rand()'s narrower/
     * predictable range, uniqid()'s timestamp-derived hex text) would
     * either fail to produce a wide spread of raw byte values at all,
     * or would produce a distribution chi-square testing flags as far
     * from uniform. The thresholds below are deliberately generous —
     * this exists to catch a grossly broken/weak generator, not to
     * certify cryptographic quality.
     */
    public function test_generate_verifier_raw_byte_distribution_behaves_like_a_csprng_across_many_samples(): void
    {
        $sampleCount = 2000;
        $byteFrequencies = array_fill(0, 256, 0);
        $totalBytes = 0;

        for ($i = 0; $i < $sampleCount; $i++) {
            $raw = $this->decodeBase64Url($this->pkce->generateVerifier());

            foreach (str_split($raw) as $byte) {
                $byteFrequencies[ord($byte)]++;
                $totalBytes++;
            }
        }

        $observedDistinctByteValues = count(array_filter($byteFrequencies, fn ($count) => $count > 0));

        $this->assertGreaterThan(
            200,
            $observedDistinctByteValues,
            "Expected close to all 256 possible byte values to appear across {$sampleCount} CSPRNG-generated verifiers; ".
            'a narrow/skewed range suggests a weak, non-cryptographic RNG.'
        );

        $expectedPerByte = $totalBytes / 256;
        $chiSquare = 0.0;
        foreach ($byteFrequencies as $observed) {
            $chiSquare += (($observed - $expectedPerByte) ** 2) / $expectedPerByte;
        }

        $this->assertLessThan(
            400.0,
            $chiSquare,
            'Byte distribution across many verifiers deviates too far from uniform for a CSPRNG-backed generator.'
        );
    }

    // ---------------------------------------------------------------
    // S256 challenge derivation
    // ---------------------------------------------------------------

    public function test_challenge_for_verifier_is_deterministic_for_the_same_input(): void
    {
        $verifier = $this->pkce->generateVerifier();

        $first = $this->pkce->challengeForVerifier($verifier);
        $second = $this->pkce->challengeForVerifier($verifier);

        $this->assertSame($first, $second);
    }

    public function test_challenge_for_verifier_differs_for_different_verifiers(): void
    {
        $challengeA = $this->pkce->challengeForVerifier($this->pkce->generateVerifier());
        $challengeB = $this->pkce->challengeForVerifier($this->pkce->generateVerifier());

        $this->assertNotSame($challengeA, $challengeB);
    }

    /**
     * Independently re-derives base64url(sha256(verifier)) using raw
     * PHP primitives, matching RFC 7636 §4.2 exactly, and asserts the
     * service's own output is byte-for-byte identical — proving the two
     * values are cryptographically linked, not independently random.
     */
    public function test_challenge_for_verifier_is_the_correct_s256_hash_of_the_verifier(): void
    {
        $verifier = 'a-known-fixture-verifier-value-for-this-test';

        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->assertSame($expected, $this->pkce->challengeForVerifier($verifier));
    }

    public function test_challenge_for_verifier_uses_the_raw_binary_digest_not_hex(): void
    {
        $verifier = 'another-fixture-verifier';

        $wrongHexBased = rtrim(strtr(base64_encode(hash('sha256', $verifier, false)), '+/', '-_'), '=');

        $this->assertNotSame($wrongHexBased, $this->pkce->challengeForVerifier($verifier));
    }

    // ---------------------------------------------------------------
    // verify() — timing-safe comparison
    // ---------------------------------------------------------------

    public function test_verify_returns_true_for_a_matching_verifier_and_challenge(): void
    {
        $verifier = $this->pkce->generateVerifier();
        $challenge = $this->pkce->challengeForVerifier($verifier);

        $this->assertTrue($this->pkce->verify($verifier, $challenge));
    }

    public function test_verify_returns_false_for_a_wrong_verifier(): void
    {
        $verifier = $this->pkce->generateVerifier();
        $challenge = $this->pkce->challengeForVerifier($verifier);
        $wrongVerifier = $this->pkce->generateVerifier();

        $this->assertFalse($this->pkce->verify($wrongVerifier, $challenge));
    }

    public function test_verify_returns_false_for_an_empty_verifier(): void
    {
        $challenge = $this->pkce->challengeForVerifier($this->pkce->generateVerifier());

        $this->assertFalse($this->pkce->verify('', $challenge));
    }

    public function test_verify_returns_false_for_a_malformed_challenge(): void
    {
        $verifier = $this->pkce->generateVerifier();

        $this->assertFalse($this->pkce->verify($verifier, 'not-a-real-challenge-value'));
    }

    /**
     * A hash_equals()-style comparator rejects a value that differs by
     * even a single trailing character exactly the same as it would
     * reject a wildly wrong one — this is the closest a black-box test
     * can get to proving timing-safe, full-value comparison is in use,
     * without measuring wall-clock timing (which would be flaky) or
     * re-grepping the source for the literal string "hash_equals(".
     */
    public function test_verify_rejects_a_near_miss_challenge_differing_by_a_single_trailing_character(): void
    {
        $verifier = $this->pkce->generateVerifier();
        $correctChallenge = $this->pkce->challengeForVerifier($verifier);

        $nearMissChallenge = $this->flipLastCharacter($correctChallenge);

        $this->assertNotSame($correctChallenge, $nearMissChallenge);
        $this->assertFalse(
            $this->pkce->verify($verifier, $nearMissChallenge),
            'A single-character near-miss challenge must be rejected — proves the comparison checks the full value, not a shortcut/prefix.'
        );
    }

    public function test_verify_rejects_a_near_miss_verifier_differing_by_a_single_trailing_character(): void
    {
        $verifier = $this->pkce->generateVerifier();
        $challenge = $this->pkce->challengeForVerifier($verifier);

        $nearMissVerifier = $this->flipLastCharacter($verifier);

        $this->assertNotSame($verifier, $nearMissVerifier);
        $this->assertFalse($this->pkce->verify($nearMissVerifier, $challenge));
    }

    // ---------------------------------------------------------------
    // No "plain" method anywhere
    // ---------------------------------------------------------------

    /**
     * Structural proof via reflection on the actual runtime class (not
     * a text scan): PkceService's entire public API surface is exactly
     * these three methods, and none of their parameters look like a
     * caller-suppliable challenge-method switch — so there is no
     * method, and no parameter on any method, a "plain" mode could
     * hide behind.
     */
    public function test_public_api_surface_has_exactly_the_expected_methods_and_no_challenge_method_parameter(): void
    {
        $reflection = new ReflectionClass(PkceService::class);

        $publicMethods = array_values(array_filter(
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $m) => ! $m->isConstructor() && ! $m->isStatic()
                && $m->getDeclaringClass()->getName() === PkceService::class
        ));

        $methodNames = array_map(fn (ReflectionMethod $m) => $m->getName(), $publicMethods);
        sort($methodNames);

        $this->assertSame(
            ['challengeForVerifier', 'generateVerifier', 'verify'],
            $methodNames,
            'PkceService must expose exactly these three methods — no additional "plain"-mode entry point can exist on the public API surface.'
        );

        foreach ($publicMethods as $method) {
            foreach ($method->getParameters() as $parameter) {
                $this->assertDoesNotMatchRegularExpression(
                    '/method|mode|plain/i',
                    $parameter->getName(),
                    "Parameter '{$parameter->getName()}' on ".$method->getName().
                    '() looks like it could be a caller-suppliable challenge-method switch — PkceService must be S256-only with no such parameter anywhere.'
                );
            }
        }
    }

    public function test_challenge_for_verifier_accepts_no_method_parameter(): void
    {
        $reflection = new ReflectionClass(PkceService::class);
        $method = $reflection->getMethod('challengeForVerifier');

        $this->assertCount(
            1,
            $method->getParameters(),
            'challengeForVerifier() must take only the verifier — no caller-suppliable challenge-method parameter can exist for a "plain" bypass to hide behind.'
        );
    }

    /**
     * base64url-decodes a string produced by PkceService's own
     * base64url alphabet (RFC 4648 §5, no padding) back to raw bytes —
     * used only to inspect the actual runtime byte distribution of
     * generateVerifier()'s output, never to reconstruct/guess service
     * internals.
     */
    private function decodeBase64Url(string $value): string
    {
        $padded = strtr($value, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);

        $decoded = base64_decode($padded, true);
        $this->assertIsString($decoded);

        return $decoded;
    }

    /**
     * Flips exactly the last character of $value to a different,
     * known-distinct character — a minimal, deterministic near-miss
     * for proving a comparison checks the full value.
     */
    private function flipLastCharacter(string $value): string
    {
        $lastChar = substr($value, -1);
        $replacement = $lastChar === 'A' ? 'B' : 'A';

        return substr($value, 0, -1).$replacement;
    }
}
