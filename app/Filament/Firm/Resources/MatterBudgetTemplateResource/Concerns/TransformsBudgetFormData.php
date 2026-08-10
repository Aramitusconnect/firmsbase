<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterBudgetTemplateResource\Concerns;

use App\Enums\FirmUserRole;
use App\Enums\MatterBudgetExpenseCategory;

/**
 * TransformsBudgetFormData — shared between MatterBudgetTemplateResource's
 * Create/Edit pages. The form exposes one plain field per closed
 * FirmUserRole/MatterBudgetExpenseCategory case (hours_<role>,
 * expenses_<category>) rather than a raw JSON editor (see the
 * Resource's own docblock); this collapses those scalar fields back
 * into the expected_hours_json/expected_expenses_json shape the
 * service layer validates and persists, and expands the JSON back into
 * the scalar fields when loading the Edit form. Expense fields are
 * entered in whole dollars in the UI and stored in cents.
 */
trait TransformsBudgetFormData
{
    /**
     * @return array{0: array<string, int>, 1: array<string, int>}
     */
    private function extractHoursAndExpenses(array $data): array
    {
        $hours = [];

        foreach (FirmUserRole::cases() as $role) {
            $value = $data["hours_{$role->value}"] ?? null;

            if ($value !== null && $value !== '') {
                $hours[$role->value] = (int) $value;
            }
        }

        $expenses = [];

        foreach (MatterBudgetExpenseCategory::cases() as $category) {
            $value = $data["expenses_{$category->value}"] ?? null;

            if ($value !== null && $value !== '') {
                $expenses[$category->value] = (int) round(((float) $value) * 100);
            }
        }

        return [$hours, $expenses];
    }

    private function expandHoursAndExpenses(array $data, array $expectedHours, array $expectedExpenses): array
    {
        foreach (FirmUserRole::cases() as $role) {
            $data["hours_{$role->value}"] = $expectedHours[$role->value] ?? null;
        }

        foreach (MatterBudgetExpenseCategory::cases() as $category) {
            $cents = $expectedExpenses[$category->value] ?? null;
            $data["expenses_{$category->value}"] = $cents === null ? null : $cents / 100;
        }

        return $data;
    }
}
