<?php

namespace App\Services;

/**
 * WebhookSignatureService — deterministic HMAC-SHA256 signing
 * (correction #6). The signed string is "{timestamp}.{canonical_payload}"
 * — binding the timestamp INTO the signature (not just alongside it)
 * means a captured request cannot be replayed later with a different
 * timestamp header without invalidating the signature. Header value
 * format is "sha256=<hex>". verify() uses hash_equals() (timing-safe)
 * and fails if the payload, timestamp, or signature has been altered,
 * or if the timestamp is outside toleranceSeconds of "now" (default 300
 * seconds / 5 minutes) — this is a reference implementation for firms'
 * own downstream systems to mirror; this codebase never verifies an
 * inbound signature itself since it only produces outbound webhooks.
 */
class WebhookSignatureService
{
    public function sign(string $rawSecret, string $timestamp, string $canonicalPayload): string
    {
        $signedString = "{$timestamp}.{$canonicalPayload}";
        $hex = hash_hmac('sha256', $signedString, $rawSecret);

        return "sha256={$hex}";
    }

    public function verify(
        string $rawSecret,
        string $timestamp,
        string $canonicalPayload,
        string $providedSignature,
        int $toleranceSeconds = 300,
    ): bool {
        if (! ctype_digit($timestamp)) {
            return false;
        }

        $timestampInt = (int) $timestamp;
        $now = time();

        if (abs($now - $timestampInt) > $toleranceSeconds) {
            return false;
        }

        $expectedSignature = $this->sign($rawSecret, $timestamp, $canonicalPayload);

        return hash_equals($expectedSignature, $providedSignature);
    }
}
