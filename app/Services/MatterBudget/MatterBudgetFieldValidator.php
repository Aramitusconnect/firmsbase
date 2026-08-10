<?php

namespace App\Services\MatterBudget;

use App\Enums\FirmUserRole;
use App\Enums\MatterBudgetExpenseCategory;

/**
 * MatterBudgetFieldValidator — Predictive Matter Budget Alerts, item
 * 3/4/18. Shared save-time validation for both
 * MatterBudgetTemplateService and MatterBudgetService: expected_hours
 * keyed only by real FirmUserRole values, expected_expenses keyed only
 * by real MatterBudgetExpenseCategory values, sane threshold/margin
 * bounds. Never an arbitrary key, never an arbitrary formula.
 */
final class MatterBudgetFieldValidator
{
    /**
     * @param  array<string, int|float>  $expectedHours
     * @param  array<string, int>  $expectedExpenses
     */
    public static function validate(
        array $expectedHours,
        array $expectedExpenses,
        int $warningThresholdPercent,
        int $highThresholdPercent,
        ?int $targetGrossMarginPercent,
    ): void {
        foreach ($expectedHours as $role => $hours) {
            if (FirmUserRole::tryFrom((string) $role) === null) {
                throw new \InvalidArgumentException("Unknown FirmUserRole [{$role}] in expected_hours.");
            }

            if (! is_numeric($hours) || $hours < 0) {
                throw new \InvalidArgumentException("Expected hours for role [{$role}] must be a non-negative number.");
            }
        }

        foreach ($expectedExpenses as $category => $cents) {
            if (MatterBudgetExpenseCategory::tryFrom((string) $category) === null) {
                throw new \InvalidArgumentException("Unknown MatterBudgetExpenseCategory [{$category}] in expected_expenses.");
            }

            if (! is_int($cents) || $cents < 0) {
                throw new \InvalidArgumentException("Expected expense cents for category [{$category}] must be a non-negative integer.");
            }
        }

        if ($warningThresholdPercent < 0 || $warningThresholdPercent > 500) {
            throw new \InvalidArgumentException('warning_threshold_percent must be between 0 and 500.');
        }

        if ($highThresholdPercent < 0 || $highThresholdPercent > 500) {
            throw new \InvalidArgumentException('high_threshold_percent must be between 0 and 500.');
        }

        if ($highThresholdPercent < $warningThresholdPercent) {
            throw new \InvalidArgumentException('high_threshold_percent must be greater than or equal to warning_threshold_percent.');
        }

        if ($targetGrossMarginPercent !== null && ($targetGrossMarginPercent < 0 || $targetGrossMarginPercent > 100)) {
            throw new \InvalidArgumentException('target_gross_margin_percent must be between 0 and 100.');
        }
    }
}
