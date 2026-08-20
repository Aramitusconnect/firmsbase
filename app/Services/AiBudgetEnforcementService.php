<?php

namespace App\Services;

use App\Enums\UsageRollupMetric;
use App\Marketplace\Models\MarketplaceAiUsageEvent;
use App\Models\AiUsageEvent;
use App\Models\Firm;
use App\ValueObjects\AiBudgetCheckResult;

/**
 * AiBudgetEnforcementService — firm-level limits read from
 * firm_ai_settings.token_limit_per_period/budget_limit_cents_per_period,
 * checked against this firm's COMBINED usage within the given period:
 * ai_usage_events (authenticated firm users) plus
 * marketplace_ai_usage_events (anonymous prospect intake turns).
 *
 * This is a pre-flight check, and the exact cost of the current call is
 * not knowable before the provider answers. Callers must therefore not
 * pass 0 for a call they are about to make — they pass a conservative
 * upper bound (estimated input plus the adapter's hard max_output_tokens
 * ceiling), so a firm at 990/1000 is stopped rather than allowed to
 * finish at 1490. The bound over-counts by design.
 *
 * What this is NOT: an atomic reservation. Two concurrent turns can each
 * pass the check before either records usage, so the limit is a strong
 * pre-flight ceiling rather than a hard cap under concurrency. Making it
 * one requires a reservation row plus row-level locking — a schema change
 * outside this change's scope.
 *
 * Organization-level budget uses the EXISTING UsageRollupService/
 * UsageRollupMetric::AiTokens pattern (Phase 6, previously unused —
 * built in anticipation of this exact phase). No second usage-
 * aggregation mechanism is introduced.
 *
 * Gap, stated plainly rather than worked around: the approved Phase
 * 15 data contract has no column anywhere for an organization-level AI
 * budget LIMIT value (firm_ai_settings is per-firm; BillingAccount has
 * no such column). checkOrganizationBudget() therefore accepts the
 * limit as a caller-supplied parameter — the mechanism (UsageRollupService
 * pattern) is fully wired and tested, but where that limit is
 * configured/stored is a decision outside this phase's approved scope.
 */
class AiBudgetEnforcementService
{
    public function __construct(private readonly UsageRollupService $usageRollupService) {}

    public function checkFirmBudget(
        Firm $firm,
        int $additionalTokens,
        int $additionalCostCents,
        \DateTimeInterface $periodStartsAt,
        \DateTimeInterface $periodEndsAt,
    ): AiBudgetCheckResult {
        $settings = $firm->aiSettings;

        // A firm's token budget is ONE ceiling over ALL of its AI spend, so it
        // has to be measured across both places that spend is recorded.
        //
        // The two tables stay separate for good reasons of their own
        // (ai_usage_events requires a user_id; marketplace turns are driven by
        // anonymous prospects who have no user record), and neither is merged
        // or duplicated here. This reads both and adds them.
        //
        // Cost is deliberately global-only below: marketplace_ai_usage_events
        // has no cost_cents column, so there is nothing to add. The token
        // ceiling is the one that binds in practice.
        $usedTokens = $this->usedTokens($firm, $periodStartsAt, $periodEndsAt);

        $usedCostCents = (int) AiUsageEvent::query()
            ->where('firm_id', $firm->id)
            ->where('created_at', '>=', $periodStartsAt)
            ->where('created_at', '<=', $periodEndsAt)
            ->sum('cost_cents');

        $withinTokenLimit = $settings?->token_limit_per_period === null
            || ($usedTokens + $additionalTokens) <= $settings->token_limit_per_period;

        $withinBudget = $settings?->budget_limit_cents_per_period === null
            || ($usedCostCents + $additionalCostCents) <= $settings->budget_limit_cents_per_period;

        if (! $withinTokenLimit) {
            return AiBudgetCheckResult::deny('Firm AI token limit for this period would be exceeded.', withinFirmTokenLimit: false);
        }

        if (! $withinBudget) {
            return AiBudgetCheckResult::deny('Firm AI budget limit for this period would be exceeded.', withinFirmBudget: false);
        }

        return AiBudgetCheckResult::allow();
    }

    /**
     * This firm's combined token usage in the period — the same figure the
     * budget check enforces against, exposed so the AI settings page can show a
     * firm what it has actually spent rather than a second, differently-derived
     * number.
     */
    public function usedTokens(Firm $firm, \DateTimeInterface $periodStartsAt, \DateTimeInterface $periodEndsAt): int
    {
        $global = (int) AiUsageEvent::query()
            ->where('firm_id', $firm->id)
            ->where('created_at', '>=', $periodStartsAt)
            ->where('created_at', '<=', $periodEndsAt)
            ->selectRaw('COALESCE(SUM(tokens_in + tokens_out), 0) as total')
            ->value('total');

        // Scoped by explicit firm_id, never by RLS alone: rows with a null
        // firm_id exist (a prospect can reach the marketplace before a firm is
        // resolved) and must not be charged to anyone.
        $marketplace = (int) MarketplaceAiUsageEvent::query()
            ->where('firm_id', $firm->id)
            ->where('created_at', '>=', $periodStartsAt)
            ->where('created_at', '<=', $periodEndsAt)
            ->selectRaw('COALESCE(SUM(tokens_in + tokens_out), 0) as total')
            ->value('total');

        return $global + $marketplace;
    }

    public function checkOrganizationBudget(
        Firm $firm,
        int $organizationBudgetLimit,
        \DateTimeInterface $periodStartsAt,
        \DateTimeInterface $periodEndsAt,
    ): bool {
        $billingAccount = $firm->billingAccount;

        if (! $billingAccount) {
            // No billing account attached — nothing to aggregate
            // against, so there is no organization-level ceiling to
            // enforce for this firm.
            return true;
        }

        return $this->usageRollupService->isWithinBudget(
            $billingAccount,
            UsageRollupMetric::AiTokens,
            $organizationBudgetLimit,
            $periodStartsAt,
            $periodEndsAt,
        );
    }
}
