<?php

namespace App\Services;

use App\Enums\UsageRollupMetric;
use App\Models\Firm;
use App\Models\AiUsageEvent;
use App\ValueObjects\AiBudgetCheckResult;

/**
 * AiBudgetEnforcementService — firm-level limits read from
 * firm_ai_settings.token_limit_per_period/budget_limit_cents_per_period,
 * checked against the sum of this firm's ai_usage_events within the
 * given period (a pre-flight check based on prior usage, not a
 * mid-call token-precision check — token/cost counts for the CURRENT
 * call are only known after the fake adapter returns, so this service
 * blocks the NEXT call once a firm is at/over its limit, which is the
 * same pattern real-world token-budget gates use).
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
    public function __construct(private readonly UsageRollupService $usageRollupService)
    {
    }

    public function checkFirmBudget(
        Firm $firm,
        int $additionalTokens,
        int $additionalCostCents,
        \DateTimeInterface $periodStartsAt,
        \DateTimeInterface $periodEndsAt,
    ): AiBudgetCheckResult {
        $settings = $firm->aiSettings;

        $usedTokens = (int) AiUsageEvent::query()
            ->where('firm_id', $firm->id)
            ->where('created_at', '>=', $periodStartsAt)
            ->where('created_at', '<=', $periodEndsAt)
            ->selectRaw('COALESCE(SUM(tokens_in + tokens_out), 0) as total')
            ->value('total');

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
