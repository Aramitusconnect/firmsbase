<?php

namespace App\Services\MatterBudget;

use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterBudget;
use App\Models\MatterBudgetTemplate;
use App\Services\TenantContextService;

/**
 * MatterBudgetService — Predictive Matter Budget Alerts, item 4/19/20.
 * The sole writer of matter_budgets. Never updates an existing row
 * (the model itself refuses — see MatterBudget::booted()); every
 * change is a brand-new, higher-version row, so a Matter's budget
 * history is always fully preserved and a later template edit never
 * silently rewrites an already-open Matter's own budget.
 */
class MatterBudgetService
{
    public function __construct(private readonly MatterBudgetAccessPolicyService $accessPolicy) {}

    /**
     * The current (highest-version) budget for a Matter, or null if
     * none has ever been set — callers must render "No Budget
     * Configured", never a zero-filled budget (item 24). Must be
     * called from inside an already-active tenant context (e.g. from
     * inside a runWithFirmContext() closure, or by a caller who has
     * already established one) — under FORCE RLS an unscoped call
     * silently returns null even when a budget exists.
     */
    public function current(Matter $matter): ?MatterBudget
    {
        return MatterBudget::query()
            ->where('matter_id', $matter->id)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Applies a template to a Matter, snapshotting its current field
     * values (and its own version number) into a new matter_budgets
     * row. If the Matter already has a budget, this counts as a
     * revision (a new, higher version) — never a silent overwrite of
     * an in-progress budget's history.
     */
    public function applyTemplate(Firm $firm, Matter $matter, MatterBudgetTemplate $template, FirmUser $appliedBy, ?string $changeReason = null): MatterBudget
    {
        $this->assertAuthorized($appliedBy);
        $this->assertSameFirm($firm, $matter, $appliedBy);

        if ((int) $template->firm_id !== (int) $firm->id) {
            throw new \RuntimeException('This matter budget template does not belong to this firm.');
        }

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $matter, $template, $appliedBy, $changeReason) {
            // The current() lookup MUST run inside this same context —
            // under FORCE RLS, an unscoped read returns zero rows even
            // when a budget already exists, which would silently
            // restart versioning at 1 and violate the unique(matter_id,
            // version) index on the very next apply (see
            // MatterReadinessService's own identical documented gotcha).
            $existing = $this->current($matter);
            $nextVersion = $existing === null ? 1 : $existing->version + 1;

            if ($existing !== null && ($changeReason === null || trim($changeReason) === '')) {
                $changeReason = "Applied template \"{$template->name}\" (template v{$template->version}).";
            }

            return MatterBudget::create([
                'firm_id' => $firm->id,
                'matter_id' => $matter->id,
                'version' => $nextVersion,
                'source_template_id' => $template->id,
                'source_template_version' => $template->version,
                'expected_duration_days' => $template->expected_duration_days,
                'expected_hours_json' => $template->expected_hours_json,
                'expected_expenses_json' => $template->expected_expenses_json,
                'expected_revenue_cents' => $template->expected_revenue_cents,
                'target_gross_margin_percent' => $template->target_gross_margin_percent,
                'warning_threshold_percent' => $template->warning_threshold_percent,
                'high_threshold_percent' => $template->high_threshold_percent,
                'change_reason' => $changeReason,
                'created_by_firm_user_id' => $appliedBy->id,
            ]);
        });
    }

    /**
     * Creates or revises a Matter-specific budget with no (or a
     * different) template backing it. A change_reason is REQUIRED
     * whenever the Matter already has a budget (item 20's own explicit
     * audit requirement) — the very first budget for a Matter has
     * nothing to compare against, so none is required there.
     *
     * @param  array<string, int|float>  $expectedHours
     * @param  array<string, int>  $expectedExpenses
     */
    public function reviseCustom(
        Firm $firm,
        Matter $matter,
        FirmUser $revisedBy,
        array $expectedHours,
        array $expectedExpenses,
        ?int $expectedDurationDays = null,
        ?int $expectedRevenueCents = null,
        ?int $targetGrossMarginPercent = null,
        int $warningThresholdPercent = 75,
        int $highThresholdPercent = 90,
        ?string $changeReason = null,
    ): MatterBudget {
        $this->assertAuthorized($revisedBy);
        $this->assertSameFirm($firm, $matter, $revisedBy);
        MatterBudgetFieldValidator::validate($expectedHours, $expectedExpenses, $warningThresholdPercent, $highThresholdPercent, $targetGrossMarginPercent);

        return (new TenantContextService)->runWithFirmContext($firm, function () use (
            $firm, $matter, $revisedBy, $expectedHours, $expectedExpenses, $expectedDurationDays,
            $expectedRevenueCents, $targetGrossMarginPercent, $warningThresholdPercent, $highThresholdPercent, $changeReason,
        ) {
            // Same context-ordering requirement as applyTemplate() above.
            $existing = $this->current($matter);
            $nextVersion = $existing === null ? 1 : $existing->version + 1;

            if ($existing !== null && ($changeReason === null || trim($changeReason) === '')) {
                throw new \InvalidArgumentException('A change reason is required when revising an existing matter budget.');
            }

            return MatterBudget::create([
                'firm_id' => $firm->id,
                'matter_id' => $matter->id,
                'version' => $nextVersion,
                'source_template_id' => null,
                'source_template_version' => null,
                'expected_duration_days' => $expectedDurationDays,
                'expected_hours_json' => $expectedHours,
                'expected_expenses_json' => $expectedExpenses,
                'expected_revenue_cents' => $expectedRevenueCents,
                'target_gross_margin_percent' => $targetGrossMarginPercent,
                'warning_threshold_percent' => $warningThresholdPercent,
                'high_threshold_percent' => $highThresholdPercent,
                'change_reason' => $changeReason,
                'created_by_firm_user_id' => $revisedBy->id,
            ]);
        });
    }

    private function assertAuthorized(FirmUser $actor): void
    {
        if (! $this->accessPolicy->canReviseMatterBudget($actor->role)) {
            throw new \RuntimeException('This user is not authorized to set or revise a matter budget.');
        }
    }

    private function assertSameFirm(Firm $firm, Matter $matter, FirmUser $actor): void
    {
        if ((int) $matter->firm_id !== (int) $firm->id || (int) $actor->firm_id !== (int) $firm->id) {
            throw new \RuntimeException('This matter does not belong to this firm.');
        }
    }
}
