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
     * @param array{max_attempts?: int, base_delay_seconds?: int, multiplier?: int} $retryPolicy
     */
    public function nextAttemptDelaySeconds(int $attemptNumber, array $retryPolicy): int
    {
        $baseDelay = (int) ($retryPolicy['base_delay_seconds'] ?? self::DEFAULT_RETRY_POLICY['base_delay_seconds']);
        $multiplier = (int) ($retryPolicy['multiplier'] ?? self::DEFAULT_RETRY_POLICY['multiplier']);

        return (int) ($baseDelay * ($multiplier ** max(0, $attemptNumber - 1)));
    }

    /**
     * @param array{max_attempts?: int, base_delay_seconds?: int, multiplier?: int} $retryPolicy
     */
    public function isExhausted(int $attemptNumber, array $retryPolicy): bool
    {
        $maxAttempts = (int) ($retryPolicy['max_attempts'] ?? self::DEFAULT_RETRY_POLICY['max_attempts']);

        return $attemptNumber >= $maxAttempts;
    }
}
