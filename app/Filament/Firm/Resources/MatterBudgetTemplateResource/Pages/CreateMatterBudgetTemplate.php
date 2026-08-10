<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterBudgetTemplateResource\Pages;

use App\Filament\Firm\Resources\MatterBudgetTemplateResource;
use App\Filament\Firm\Resources\MatterBudgetTemplateResource\Concerns\TransformsBudgetFormData;
use App\Models\Firm;
use App\Models\MatterBudgetTemplate;
use App\Services\MatterBudget\MatterBudgetTemplateService;
use App\Services\TenantContextService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * CreateMatterBudgetTemplate — routes through
 * MatterBudgetTemplateService::create(), never a bare Eloquent save,
 * mirroring CreateAutomationRule's own established precedent.
 */
class CreateMatterBudgetTemplate extends CreateRecord
{
    use TransformsBudgetFormData;

    protected static string $resource = MatterBudgetTemplateResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $firmUser = Auth::user()?->activeFirmUser();

        abort_unless($firmUser !== null, 403);

        [$hours, $expenses] = $this->extractHoursAndExpenses($data);

        return app(TenantContextService::class)->runWithFirmContext(
            (int) $firmUser->firm_id,
            function () use ($data, $firmUser, $hours, $expenses): MatterBudgetTemplate {
                $firm = Firm::query()->findOrFail($firmUser->firm_id);

                return app(MatterBudgetTemplateService::class)->create(
                    firm: $firm,
                    createdBy: $firmUser,
                    name: $data['name'],
                    description: $data['description'] ?? null,
                    practiceAreaId: isset($data['practice_area_id']) ? (int) $data['practice_area_id'] : null,
                    matterTypeId: isset($data['matter_type_id']) ? (int) $data['matter_type_id'] : null,
                    expectedHours: $hours,
                    expectedExpenses: $expenses,
                    expectedDurationDays: isset($data['expected_duration_days']) ? (int) $data['expected_duration_days'] : null,
                    expectedRevenueCents: isset($data['expected_revenue_cents']) ? (int) round($data['expected_revenue_cents'] * 100) : null,
                    targetGrossMarginPercent: isset($data['target_gross_margin_percent']) ? (int) $data['target_gross_margin_percent'] : null,
                    warningThresholdPercent: (int) ($data['warning_threshold_percent'] ?? 75),
                    highThresholdPercent: (int) ($data['high_threshold_percent'] ?? 90),
                );
            },
        );
    }
}
