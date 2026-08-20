<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Marketplace\Models\MarketplaceAiUsageEvent;
use App\Models\Firm;
use App\Services\TenantContextService;
use App\ValueObjects\AiAccessDecision;
use Illuminate\Support\Facades\RateLimiter;

/**
 * MarketplaceAiUsageThrottleService — Mission 3 (MyAttorney Conversion
 * + AI Intake), checkpoint 6, requirement #12: "abuse/cost limits for
 * anonymous AI use: per-IP, per-session, request ceiling, token/message
 * ceiling where applicable." Mirrors AccountLoginThrottleService's own
 * RateLimiter-wrapper convention for the two fast, count-based
 * ceilings (per-IP, per-session requests) and adds a third, DB-backed
 * rolling-window ceiling (tokens consumed) so a determined abuser
 * cannot evade the request-count ceilings by sending very few, very
 * large requests instead of many small ones.
 *
 * Every ceiling here applies EQUALLY regardless of authentication
 * state, because both callers of this service
 * (MarketplaceIssueClassifierService,
 * MarketplaceIntakeConversationalAssistantService) are, by definition,
 * anonymous-actor call sites — there is no authenticated-user
 * exemption to apply.
 */
class MarketplaceAiUsageThrottleService
{
    public const MAX_REQUESTS_PER_IP_PER_HOUR = 30;

    public const MAX_REQUESTS_PER_SESSION_PER_HOUR = 20;

    public const DECAY_MINUTES = 60;

    /**
     * A per-session ceiling on anonymous spend, independent of the
     * firm's own budget: one prospect must not be able to consume a
     * firm's entire period allowance in a single sitting.
     */
    public const MAX_TOKENS_PER_SESSION_PER_WINDOW = 20000;

    public const TOKEN_WINDOW_HOURS = 24;

    public function __construct(
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    public function tooManyAttemptsForIp(string $ipAddress): bool
    {
        return RateLimiter::tooManyAttempts($this->ipKey($ipAddress), self::MAX_REQUESTS_PER_IP_PER_HOUR);
    }

    public function hitIp(string $ipAddress): void
    {
        RateLimiter::hit($this->ipKey($ipAddress), self::DECAY_MINUTES * 60);
    }

    public function tooManyAttemptsForSession(string $sessionHash): bool
    {
        return RateLimiter::tooManyAttempts($this->sessionKey($sessionHash), self::MAX_REQUESTS_PER_SESSION_PER_HOUR);
    }

    public function hitSession(string $sessionHash): void
    {
        RateLimiter::hit($this->sessionKey($sessionHash), self::DECAY_MINUTES * 60);
    }

    /**
     * The token ceiling is read from marketplace_ai_usage_events itself
     * rather than a RateLimiter counter, since it must sum a real
     * value (tokens_in + tokens_out) rather than count calls. Scoped
     * to exactly the same tenant-visibility boundary the caller is
     * already operating in: $firm null reads only the platform/public
     * rows (matches MarketplaceIssueClassifierService's own call
     * shape); $firm set reads only that firm's own rows (matches
     * MarketplaceIntakeConversationalAssistantService's).
     */
    public function tokensUsedInWindow(?Firm $firm, string $sessionHash): int
    {
        $since = now()->subHours(self::TOKEN_WINDOW_HOURS);

        $query = fn () => (int) MarketplaceAiUsageEvent::query()
            ->where('session_hash', $sessionHash)
            ->where('created_at', '>=', $since)
            ->selectRaw('COALESCE(SUM(tokens_in + tokens_out), 0) as total')
            ->value('total');

        return $firm !== null
            ? $this->tenantContext->runWithFirmContext($firm, $query)
            : $this->tenantContext->runWithoutFirmContext($query);
    }

    public function exceedsTokenCeiling(?Firm $firm, string $sessionHash): bool
    {
        return $this->tokensUsedInWindow($firm, $sessionHash) >= self::MAX_TOKENS_PER_SESSION_PER_WINDOW;
    }

    /**
     * The single call site every anonymous AI entry point should use
     * BEFORE attempting a provider call — combines all three ceilings
     * into one explainable decision. Never throws; callers decide
     * whether a denial means "fall back to deterministic" or "reject
     * the request."
     */
    public function evaluate(?Firm $firm, string $sessionHash, ?string $ipAddress): AiAccessDecision
    {
        if ($ipAddress !== null && $this->tooManyAttemptsForIp($ipAddress)) {
            return AiAccessDecision::deny('Too many AI requests from this address. Please try again later.');
        }

        if ($this->tooManyAttemptsForSession($sessionHash)) {
            return AiAccessDecision::deny('Too many AI requests for this session. Please try again later.');
        }

        if ($this->exceedsTokenCeiling($firm, $sessionHash)) {
            return AiAccessDecision::deny('AI usage limit reached for this session.');
        }

        return AiAccessDecision::allow();
    }

    /**
     * Records the request-count side of the ceilings (IP + session).
     * The token side needs no separate "hit" call — it is derived live
     * from marketplace_ai_usage_events, which
     * MarketplaceAiUsageRecorderService writes to on every recorded
     * call.
     */
    public function recordAttempt(string $sessionHash, ?string $ipAddress): void
    {
        if ($ipAddress !== null) {
            $this->hitIp($ipAddress);
        }

        $this->hitSession($sessionHash);
    }

    private function ipKey(string $ipAddress): string
    {
        return 'marketplace-ai-throttle-ip:'.sha1($ipAddress);
    }

    private function sessionKey(string $sessionHash): string
    {
        return 'marketplace-ai-throttle-session:'.sha1($sessionHash);
    }
}
