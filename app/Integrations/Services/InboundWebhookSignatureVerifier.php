<?php

declare(strict_types=1);

namespace App\Integrations\Services;

/**
 * InboundWebhookSignatureVerifier — originally STEP 4 of Checkpoint 7's
 * frozen four-step identity-scoped secret-resolution mechanism
 * (reviews/checkpoint-07/frozen-design-post-security-review.md §5/§8;
 * agent-7h-security-design-review.md §1.5). The narrow, pure,
 * HTTP-request-agnostic verification boundary: every method here
 * operates only on already-extracted strings, never on an
 * `Illuminate\Http\Request` directly — case-insensitive header lookup
 * and duplicate-header rejection are the CALLER's responsibility
 * (historically App\Integrations\Http\Controllers\InboundWebhookController;
 * still App\Integrations\Providers\TestProvider\TestProvider, which
 * has access to the real header collection this class deliberately
 * does not need).
 *
 * CHECKPOINT 1 (FirmsVault Live Integrations) RE-SCOPE
 * (checkpoint1-design-webhook-verification.md §2): no longer "the"
 * verification mechanism. As of this checkpoint,
 * InboundWebhookController no longer calls this class directly at
 * all — verification is fully delegated per-provider via
 * App\Integrations\Contracts\SupportsWebhooksContract::verifyInboundSignature().
 * The only remaining production caller is
 * App\Integrations\Providers\TestProvider\TestProvider::verifyInboundSignature(),
 * which continues to construct and call this class exactly as before,
 * now genuinely exercised through the real controller flow (via the
 * contract) rather than only via direct unit tests. Retained here,
 * unmoved, as an optional, explicitly-reusable HMAC-SHA256 +
 * timestamp + replay-window helper for any FUTURE provider whose own
 * webhook scheme happens to match this exact shape — it is not a
 * required or default scheme for a new provider to adopt, and none of
 * Microsoft Graph/Google/Plaid use it.
 *
 * Never logs, persists, or re-throws any secret candidate passed to
 * it. Callers MUST discard every plaintext candidate immediately after
 * `verify()` returns (frozen design §5 STEP 4's "discard $plaintext
 * immediately" instruction, generalized here to "every candidate",
 * since this class accepts up to two per frozen design §8's rotation
 * contract).
 *
 * Collapse-to-false (frozen design §8's core principle): EVERY
 * structural/cryptographic failure mode — missing/duplicate header,
 * malformed timestamp, malformed signature, unsupported algorithm
 * version, expired/future timestamp, wrong secret — returns the exact
 * same untyped `false`. This class never exposes, via return value or
 * exception, WHICH specific check failed.
 */
final class InboundWebhookSignatureVerifier
{
    /**
     * ±300 seconds, inclusive boundary, one unified window (frozen
     * design §8 — no stacked skew allowance).
     */
    private const REPLAY_WINDOW_SECONDS = 300;

    /**
     * Hardcoded, closed, one-entry allowlist (frozen design §8):
     * algorithm is NEVER chosen by attacker input — this array is
     * consulted only to confirm the header's version label is exactly
     * the one supported value; the algorithm identifier used in the
     * actual `hash_hmac()` call below is always this literal value,
     * never interpolated from request data.
     *
     * @var array<string, string>
     */
    private const ALGORITHM_ALLOWLIST = ['v1' => 'sha256'];

    /**
     * A fixed, non-secret, hardcoded dummy key — used ONLY by
     * `performConstantWorkPadding()` below, NEVER as a real signing
     * secret candidate. Deliberately not derived from any request or
     * environment value, so its cost profile is identical on every
     * call regardless of what triggered the early-exit path.
     */
    private const DUMMY_PADDING_KEY = 'checkpoint7-timing-oracle-mitigation-fixed-non-secret-dummy-key-v1';

    /**
     * Verifies a raw inbound webhook body against up to 2 secret
     * candidates (frozen design §8's rotation contract: current Active
     * first, then the most-recent Rotated credential within the
     * configurable overlap window — see
     * App\Integrations\Services\WebhookConnectionResolverService::activeAndPreviousWebhookSecretsFor()).
     * The candidate loop lives entirely inside this method (frozen
     * design §8: "loop lives entirely inside the narrow verifier
     * boundary; plaintext values never escape it").
     *
     * $rawBody MUST be the exact raw request bytes
     * (`$request->getContent()`, read before any `$request->all()`/
     * `input()`/`json()` call) — never a re-serialized/re-decoded form.
     * $timestampHeaderValue/$signatureHeaderValue are the caller's
     * already-resolved, single (never duplicate), raw header string
     * values — pass null for "header missing or appeared more than
     * once", which this method treats identically to "malformed".
     *
     * @param  string[]  $secretPlaintextCandidates  at most 2 entries; only the first 2 are ever consulted.
     */
    public function verify(
        array $secretPlaintextCandidates,
        string $rawBody,
        ?string $timestampHeaderValue,
        ?string $signatureHeaderValue,
    ): bool {
        if ($timestampHeaderValue === null || $signatureHeaderValue === null) {
            return false;
        }

        if (! $this->isStructurallyValidTimestamp($timestampHeaderValue)) {
            return false;
        }

        $hex = $this->extractValidatedHexDigest($signatureHeaderValue);

        if ($hex === null) {
            return false;
        }

        if (! $this->withinReplayWindow((int) $timestampHeaderValue)) {
            return false;
        }

        // "v1" is a hardcoded literal here, deliberately NOT the parsed
        // header version label — the version label was already checked
        // for exact equality against the one allowlisted value inside
        // extractValidatedHexDigest() above, so using the literal below
        // is equivalent in every reachable case, and this avoids ever
        // interpolating attacker-supplied bytes into the signing input
        // where a literal constant would do (frozen design §8: "v1" is
        // a hardcoded constant, never attacker-supplied).
        $signingInput = 'v1'.':'.$timestampHeaderValue.'.'.$rawBody;

        foreach (array_slice($secretPlaintextCandidates, 0, 2) as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            $expected = hash_hmac(self::ALGORITHM_ALLOWLIST['v1'], $signingInput, $candidate);

            if (hash_equals($expected, $hex)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Timing-oracle mitigation (originally frozen design §9): callers
     * MUST invoke this exactly once, before returning a rejection,
     * whenever no connection identity could be resolved yet (unknown
     * provider, unknown routing token, or any other early-exit
     * rejection reached before a real tenant-context/RLS-scoped read
     * happens) — the "one indexed lookup" early-exit path. Performs one
     * fixed-cost `hash_hmac()` + `hash_equals()` call against the fixed,
     * non-secret dummy key above, so the cryptographic-work floor for
     * that early-exit path is comparable to the "connection found"
     * path's own real transaction/RLS-read/AES-decrypt/HMAC-compare
     * cost. Deliberately NOT invoked for the "connection found but not
     * usable" case (disconnected connection) — that path already
     * performed the real, comparable work (a transaction, `SET LOCAL`,
     * an RLS-scoped read) before concluding there is nothing to verify
     * against.
     *
     * CHECKPOINT 1 note: this call is now purely a convenience reuse of
     * an existing implementation — its only job is "spend a comparable,
     * fixed amount of CPU," not "be semantically about HMAC signatures"
     * — so App\Integrations\Http\Controllers\InboundWebhookController
     * may keep calling this method for its own early-exit padding
     * (provider/routing-identifier resolution failures) even though it
     * no longer calls anything else on this class.
     */
    public function performConstantWorkPadding(): void
    {
        $dummySigningInput = 'v1:0000000000.checkpoint7-timing-oracle-mitigation-constant-work-padding';

        $dummyDigest = hash_hmac(self::ALGORITHM_ALLOWLIST['v1'], $dummySigningInput, self::DUMMY_PADDING_KEY);

        // The comparison operand is a fixed, well-formed 64-hex-char
        // string that never equals $dummyDigest — the point is only to
        // spend one real hash_equals() call, never to "succeed".
        hash_equals($dummyDigest, str_repeat('0', 64));
    }

    /**
     * ctype_digit, non-empty, <=11 chars (frozen design §8) — rejects
     * empty strings, any non-digit character (including a leading `-`
     * or `+`), and anything long enough to risk integer overflow on
     * cast.
     */
    private function isStructurallyValidTimestamp(string $value): bool
    {
        return $value !== '' && strlen($value) <= 11 && ctype_digit($value);
    }

    /**
     * Parses a `v1=<hex>` header value, checks the version label
     * against the hardcoded allowlist, then structurally validates the
     * hex digest (`/^[0-9a-f]{64}$/`) BEFORE this class ever calls
     * `hash_equals()` — guaranteeing that function only ever runs on a
     * well-formed, equal-length operand pair (frozen design §8).
     * Returns null on ANY structural failure (missing `=`, unknown
     * version label, malformed hex) — collapsed into the same `false`
     * result by verify() above.
     */
    private function extractValidatedHexDigest(string $signatureHeaderValue): ?string
    {
        if (! str_contains($signatureHeaderValue, '=')) {
            return null;
        }

        [$versionLabel, $hex] = explode('=', $signatureHeaderValue, 2);

        if (! array_key_exists($versionLabel, self::ALGORITHM_ALLOWLIST)) {
            return null;
        }

        if (! preg_match('/^[0-9a-f]{64}$/', $hex)) {
            return null;
        }

        return $hex;
    }

    private function withinReplayWindow(int $timestamp): bool
    {
        return abs(now()->getTimestamp() - $timestamp) <= self::REPLAY_WINDOW_SECONDS;
    }
}
