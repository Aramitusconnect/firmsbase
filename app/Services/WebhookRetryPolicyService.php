<?php

namespace App\Services;

/**
 * WebhookRetryPolicyService — pure calculation, no I/O, no persistence
 * (correction #12). Deterministic exponential backoff only — no
 * randomized jitter in Phase 14 (documented as a future addition, not
 * implemented now). Default retry_policy_json (used by
 * WebhookSubscriptionService when none is supplied):
 * max_attempts=5, base_delay_seconds=30, multiplier=2.
 */
class WebhookRetryPolicyService
{
    public const DEFAULT_RETRY_POLICY = [
        'max_attempts' => 5,
        'base_delay_seconds' => 30,
        'multiplier' => 2,
    ];

    /**
     * CHECKPOINT 8 addition (agent-8e-retry-backoff-ratelimit-design.md
     * §1/§6). Categories in this closed list force isExhausted() to
     * return true on the FIRST occurrence, independent of $attempts/
     * max_attempts — a credential that is invalid on attempt 1 is
     * invalid on attempt 10, and these categories must never merely
     * retry-until-max_attempts-eventually-exhausts.
     */
    public const TERMINAL_CATEGORIES = [
        'authentication_failed',
        'authorization_failed',
        'validation_failed',
        'conflict',
        'configuration_error',
        'connection_unavailable',
        'invalid_grant',
        // Checkpoint 2 (FirmsVault Live Integrations, Microsoft 365
        // provider — checkpoint2-design-sync-webhooks.md §1.4;
        // checkpoint2-combined-design.md §2 P-9/P-12) addition: retrying
        // against an expired/invalid delta cursor (e.g. Microsoft
        // Graph's `410 Gone`) without first invalidating it is
        // pointless — every retry would hit the identical error. This
        // exception category is used by the sync-item/outbox retry
        // machinery generically, so it lives here alongside the rest of
        // the closed terminal-category set rather than inventing
        // Checkpoint-2-specific retry logic.
        'cursor_expired',
    ];

    /**
     * CHECKPOINT 8 addition (agent-8e-retry-backoff-ratelimit-design.md
     * §2): $retryPolicy['max_delay_seconds'], when present, caps the
     * uncapped exponential formula below. Unset/null reproduces today's
     * exact uncapped behavior byte-for-byte — zero-risk, backward-
     * compatible, no existing call site or test passes this key.
     *
     * @param  array{max_attempts?: int, base_delay_seconds?: int, multiplier?: int, max_delay_seconds?: int}  $retryPolicy
     */
    public function nextAttemptDelaySeconds(int $attemptNumber, array $retryPolicy): int
    {
        $baseDelay = (int) ($retryPolicy['base_delay_seconds'] ?? self::DEFAULT_RETRY_POLICY['base_delay_seconds']);
        $multiplier = (int) ($retryPolicy['multiplier'] ?? self::DEFAULT_RETRY_POLICY['multiplier']);
        $raw = (int) ($baseDelay * ($multiplier ** max(0, $attemptNumber - 1)));

        $cap = $retryPolicy['max_delay_seconds'] ?? null;

        return $cap === null ? $raw : min($raw, (int) $cap);
    }

    /**
     * CHECKPOINT 8 addition (agent-8e-retry-backoff-ratelimit-design.md
     * §1/§6): optional $retryPolicy['category'] + ['category_max_attempts']
     * keys, both absent by default so every existing call site's
     * behavior (attempt-count-only exhaustion) is unaffected. When
     * $category is one of TERMINAL_CATEGORIES, this returns true
     * regardless of $attemptNumber/max_attempts — a forced first-
     * occurrence exhaustion. Otherwise, an optional per-category
     * max-attempts override (never larger than the row's own
     * max_attempts) narrows the effective ceiling for a bounded-but-
     * smaller category (e.g. malformed_response).
     *
     * @param  array{max_attempts?: int, base_delay_seconds?: int, multiplier?: int, category?: string, category_max_attempts?: array<string,int>}  $retryPolicy
     */
    public function isExhausted(int $attemptNumber, array $retryPolicy): bool
    {
        $category = $retryPolicy['category'] ?? null;

        if ($category !== null && in_array($category, self::TERMINAL_CATEGORIES, true)) {
            return true;
        }

        $maxAttempts = (int) ($retryPolicy['max_attempts'] ?? self::DEFAULT_RETRY_POLICY['max_attempts']);

        if ($category !== null) {
            $categoryOverride = $retryPolicy['category_max_attempts'][$category] ?? null;

            if ($categoryOverride !== null) {
                $maxAttempts = min($maxAttempts, (int) $categoryOverride);
            }
        }

        return $attemptNumber >= $maxAttempts;
    }
}
