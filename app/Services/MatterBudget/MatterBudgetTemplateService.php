<?php

namespace App\Services\MatterBudget;

use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\MatterBudgetTemplate;
use App\Services\TenantContextService;

/**
 * MatterBudgetTemplateService — Predictive Matter Budget Alerts, item
 * 3/18. The sole writer of matter_budget_templates. Every save
 * validates expected_hours_json against the closed FirmUserRole
 * vocabulary and expected_expenses_json against the closed
 * MatterBudgetExpenseCategory vocabulary — never an arbitrary key,
 * never an arbitrary formula, matching the Automation Engine's own
 * "closed condition/action vocabulary" discipline applied here to
 * this feature's numeric inputs.
 */
class MatterBudgetTemplateService
{
    public function __construct(private readonly MatterBudgetAccessPolicyService $accessPolicy) {}

    /**
     * @param  array<string, int|float>  $expectedHours
     * @param  array<string, int>  $expectedExpenses
     */
    public function create(
        Firm $firm,
        FirmUser $createdBy,
        string $name,
        ?string $description,
        ?int $practiceAreaId,
        ?int $matterTypeId,
        array $expectedHours,
        array $expectedExpenses,
        ?int $expectedDurationDays = null,
        ?int $expectedRevenueCents = null,
        ?int $targetGrossMarginPercent = null,
        int $warningThresholdPercent = 75,
        int $highThresholdPercent = 90,
    ): MatterBudgetTemplate {
        $this->assertAuthorized($createdBy);
        $this->validate($expectedHours, $expectedExpenses, $warningThresholdPercent, $highThresholdPercent, $targetGrossMarginPercent);

        return (new TenantContextService)->runWithFirmContext($firm, fn () => MatterBudgetTemplate::create([
            'firm_id' => $firm->id,
            'name' => $name,
            'description' => $description,
            'practice_area_id' => $practiceAreaId,
            'matter_type_id' => $matterTypeId,
            'expected_duration_days' => $expectedDurationDays,
            'expected_hours_json' => $expectedHours,
            'expected_expenses_json' => $expectedExpenses,
            'expected_revenue_cents' => $expectedRevenueCents,
            'target_gross_margin_percent' => $targetGrossMarginPercent,
            'warning_threshold_percent' => $warningThresholdPercent,
            'high_threshold_percent' => $highThresholdPercent,
            'active' => true,
            'version' => 1,
            'created_by_firm_user_id' => $createdBy->id,
            'updated_by_firm_user_id' => $createdBy->id,
        ]));
    }

    public function update(
        Firm $firm,
        MatterBudgetTemplate $template,
        FirmUser $updatedBy,
        ?string $name = null,
        ?string $description = null,
        ?array $expectedHours = null,
        ?array $expectedExpenses = null,
        ?int $expectedDurationDays = null,
        ?int $expectedRevenueCents = null,
        ?int $targetGrossMarginPercent = null,
        ?int $warningThresholdPercent = null,
        ?int $highThresholdPercent = null,
    ): MatterBudgetTemplate {
        $this->assertAuthorized($updatedBy);
        $this->assertBelongsToFirm($firm, $template, $updatedBy);

        $newHours = $expectedHours ?? $template->expected_hours_json;
        $newExpenses = $expectedExpenses ?? $template->expected_expenses_json;
        $newWarning = $warningThresholdPercent ?? $template->warning_threshold_percent;
        $newHigh = $highThresholdPercent ?? $template->high_threshold_percent;
        $newMargin = $targetGrossMarginPercent ?? $template->target_gross_margin_percent;

        $this->validate($newHours, $newExpenses, $newWarning, $newHigh, $newMargin);

        $definitionChanged = $expectedHours !== null || $expectedExpenses !== null
            || $expectedDurationDays !== null || $expectedRevenueCents !== null
            || $targetGrossMarginPercent !== null || $warningThresholdPercent !== null || $highThresholdPercent !== null;

        return (new TenantContextService)->runWithFirmContext($firm, function () use (
            $template, $updatedBy, $name, $description, $newHours, $newExpenses,
            $expectedDurationDays, $expectedRevenueCents, $newMargin, $newWarning, $newHigh, $definitionChanged,
        ) {
            $template->update([
                'name' => $name ?? $template->name,
                'description' => $description ?? $template->description,
                'expected_duration_days' => $expectedDurationDays ?? $template->expected_duration_days,
                'expected_hours_json' => $newHours,
                'expected_expenses_json' => $newExpenses,
                'expected_revenue_cents' => $expectedRevenueCents ?? $template->expected_revenue_cents,
                'target_gross_margin_percent' => $newMargin,
                'warning_threshold_percent' => $newWarning,
                'high_threshold_percent' => $newHigh,
                'version' => $definitionChanged ? $template->version + 1 : $template->version,
                'updated_by_firm_user_id' => $updatedBy->id,
            ]);

            return $template->fresh();
        });
    }

    public function setActive(Firm $firm, MatterBudgetTemplate $template, bool $active, FirmUser $updatedBy): MatterBudgetTemplate
    {
        $this->assertAuthorized($updatedBy);
        $this->assertBelongsToFirm($firm, $template, $updatedBy);

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($template, $active, $updatedBy) {
            $template->update(['active' => $active, 'updated_by_firm_user_id' => $updatedBy->id]);

            return $template->fresh();
        });
    }

    public function duplicate(Firm $firm, MatterBudgetTemplate $template, FirmUser $createdBy, string $newName): MatterBudgetTemplate
    {
        $this->assertAuthorized($createdBy);
        $this->assertBelongsToFirm($firm, $template, $createdBy);

        return $this->create(
            $firm,
            $createdBy,
            $newName,
            $template->description,
            $template->practice_area_id,
            $template->matter_type_id,
            $template->expected_hours_json,
            $template->expected_expenses_json,
            $template->expected_duration_days,
            $template->expected_revenue_cents,
            $template->target_gross_margin_percent,
            $template->warning_threshold_percent,
            $template->high_threshold_percent,
        );
    }

    private function assertAuthorized(FirmUser $actor): void
    {
        if (! $this->accessPolicy->canManageTemplates($actor->role)) {
            throw new \RuntimeException('This user is not authorized to manage matter budget templates.');
        }
    }

    private function assertBelongsToFirm(Firm $firm, MatterBudgetTemplate $template, FirmUser $actor): void
    {
        if ((int) $template->firm_id !== (int) $firm->id || (int) $actor->firm_id !== (int) $firm->id) {
            throw new \RuntimeException('This matter budget template does not belong to this firm.');
        }
    }

    /**
     * @param  array<string, int|float>  $expectedHours
     * @param  array<string, int>  $expectedExpenses
     */
    private function validate(array $expectedHours, array $expectedExpenses, int $warningThresholdPercent, int $highThresholdPercent, ?int $targetGrossMarginPercent): void
    {
        MatterBudgetFieldValidator::validate($expectedHours, $expectedExpenses, $warningThresholdPercent, $highThresholdPercent, $targetGrossMarginPercent);
    }
}
