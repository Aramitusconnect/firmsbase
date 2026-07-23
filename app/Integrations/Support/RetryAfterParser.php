<?php

declare(strict_types=1);

namespace App\Integrations\Support;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * RetryAfterParser — strict parsing of a (simulated, TestProvider-only —
 * no real network call exists anywhere in this mission) provider's
 * Retry-After-shaped value. Handles both RFC 7231 forms: delta-seconds
 * ("120") and HTTP-date ("Wed, 21 Oct 2026 07:28:00 GMT"). Never trusts
 * an unbounded or malicious value — every successfully parsed result is
 * clamped to [0, $maxSeconds] before being returned. $now is
 * caller-supplied (this class never internally calls now()/time()) so
 * parsing is fully deterministic under a frozen test clock.
 *
 * FROZEN DESIGN (agent-8e-retry-backoff-ratelimit-design.md §4):
 * $maxSeconds should be constructed with the SAME ceiling
 * IntegrationOutboxEventService's own backoff cap uses
 * (config('integrations.outbox.max_backoff_seconds')) — a provider
 * (real or simulated) must never be able to force a wait longer than
 * this system's own self-imposed maximum.
 *
 * Never-throws contract: malformed input (garbage string, a digit
 * string that fails the strict regex, an HTTP-date that doesn't match
 * RFC 7231 exactly) returns null, never an exception — a broken/hostile
 * Retry-After value must degrade to "ignore the signal, use computed
 * backoff," never block the retry pipeline.
 */
final class RetryAfterParser
{
    public function __construct(private readonly int $maxSeconds)
    {
    }

    public function parse(string $rawValue, DateTimeImmutable $now): ?int
    {
        $trimmed = trim($rawValue);

        if ($trimmed === '') {
            return null;
        }

        // Delta-seconds form: strictly unsigned digits only — no sign,
        // no decimal point, no leading/trailing garbage.
        if (preg_match('/^\d+$/', $trimmed) === 1) {
            return $this->clamp((int) $trimmed);
        }

        // HTTP-date form (RFC 7231 / RFC 1123 §7.1.1.1) — no hand-rolled
        // date parsing, no lenient/creative-parsing fallback.
        $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::RFC7231, $trimmed);

        if ($parsed === false) {
            return null;
        }

        return $this->clamp($parsed->getTimestamp() - $now->getTimestamp());
    }

    /**
     * Hard floor of 0 (a past/zero date must never produce a negative
     * delay) and hard ceiling of $maxSeconds (a provider can never
     * demand a longer wait than this system is willing to honor).
     */
    private function clamp(int $seconds): int
    {
        return max(0, min($seconds, $this->maxSeconds));
    }
}
