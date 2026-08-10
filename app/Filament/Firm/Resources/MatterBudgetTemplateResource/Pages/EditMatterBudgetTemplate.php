<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterBudgetTemplateResource\Pages;

use App\Filament\Firm\Resources\MatterBudgetTemplateResource;
use App\Filament\Firm\Resources\MatterBudgetTemplateResource\Concerns\TransformsBudgetFormData;
use App\Models\Firm;
use App\Models\MatterBudgetTemplate;
use App\Services\MatterBudget\MatterBudgetTemplateService;
use App\Services\TenantContextService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * EditMatterBudgetTemplate — routes through
 * MatterBudgetTemplateService::update(). No DeleteAction: a template
 * that has already been applied to a Matter must remain resolvable
 * from that Matter's own snapshot (source_template_id) — deactivate
 * (the `active` toggle) instead, matching
 * AutomationRuleResource/EditAutomationRule's own "disable, never
 * delete" precedent for a governed resource.
 */
class EditMatterBudgetTemplate extends EditRecord
{
    use TransformsBudgetFormData;

    protected static string $resource = MatterBudgetTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->expandHoursAndExpenses($data, $this->record->expected_hours_json, $this->record->expected_expenses_json);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $firmUser = Auth::user()?->activeFirmUser();

        abort_unless($firmUser !== null, 403);

        [$hours, $expenses] = $this->extractHoursAndExpenses($data);

        return app(TenantContextService::class)->runWithFirmContext(
            (int) $firmUser->firm_id,
            function () use ($record, $data, $firmUser, $hours, $expenses): MatterBudgetTemplate {
                $firm = Firm::query()->findOrFail($firmUser->firm_id);

                return app(MatterBudgetTemplateService::class)->update(
                    firm: $firm,
                    template: $record,
                    updatedBy: $firmUser,
                    name: $data['name'] ?? null,
                    description: $data['description'] ?? null,
                    expectedHours: $hours,
                    expectedExpenses: $expenses,
                    expectedDurationDays: isset($data['expected_duration_days']) ? (int) $data['expected_duration_days'] : null,
                    expectedRevenueCents: isset($data['expected_revenue_cents']) ? (int) round($data['expected_revenue_cents'] * 100) : null,
                    targetGrossMarginPercent: isset($data['target_gross_margin_percent']) ? (int) $data['target_gross_margin_percent'] : null,
                    warningThresholdPercent: isset($data['warning_threshold_percent']) ? (int) $data['warning_threshold_percent'] : null,
                    highThresholdPercent: isset($data['high_threshold_percent']) ? (int) $data['high_threshold_percent'] : null,
                );
            },
        );
    }
}
