<?php

declare(strict_types=1);

namespace App\Integrations\Support;

/**
 * PkceService — RFC 7636 Proof Key for Code Exchange, S256 challenge
 * method ONLY (never "plain" — checkpoint-00-final-specification.md
 * §12; frozen-design-post-review.md item 12; agent-h-security-architecture-review.md
 * item 8). The verifier this class generates is envelope-encrypted by
 * IntegrationOAuthStateService before being persisted
 * (integration_oauth_states.verifier_ciphertext) — this class itself
 * never touches storage or encryption, only pure, in-memory string
 * generation/derivation/comparison.
 *
 * generateVerifier() uses `random_bytes()` (CSPRNG), never
 * `Str::random()`'s default path — matching the same CSPRNG discipline
 * IntegrationOAuthStateService's own raw `state=` token generation
 * follows (Agent H review item 7: "matching PkceService::generateVerifier()'s
 * own discipline").
 */
final class PkceService
{
    /**
     * 32 raw bytes (256 bits) of entropy, base64url-encoded without
     * padding -> a 43-character verifier. RFC 7636 requires a verifier
     * of 43-128 characters drawn from [A-Z] / [a-z] / [0-9] / "-" / "."
     * / "_" / "~" — a base64url alphabet without padding satisfies this
     * exactly (256 bits of entropy comfortably exceeds RFC 7636's own
     * minimum recommendation).
     */
    private const VERIFIER_ENTROPY_BYTES = 32;

    public function generateVerifier(): string
    {
        return $this->base64UrlEncode(random_bytes(self::VERIFIER_ENTROPY_BYTES));
    }

    /**
     * S256 challenge derivation: base64url(sha256(verifier)), raw
     * binary digest (not hex), per RFC 7636 §4.2. Pure computation, no
     * side effects — safe to call as many times as needed to re-derive
     * the challenge from an already-known verifier.
     */
    public function challengeForVerifier(string $verifier): string
    {
        return $this->base64UrlEncode(hash('sha256', $verifier, true));
    }

    /**
     * Verifies a caller-supplied verifier against a previously-issued
     * challenge. Uses hash_equals() — this compares a caller-supplied
     * value against a stored value in application code, unlike the
     * opaque_token_hash lookup (an ordinary DB equality/index lookup,
     * not a manual comparison), so timing-safe comparison is required
     * here (Agent H review item 8).
     */
    public function verify(string $verifier, string $expectedChallenge): bool
    {
        return hash_equals($expectedChallenge, $this->challengeForVerifier($verifier));
    }

    private function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
