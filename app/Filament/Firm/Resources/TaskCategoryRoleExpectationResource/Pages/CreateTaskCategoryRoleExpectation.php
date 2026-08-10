<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TaskCategoryRoleExpectationResource\Pages;

use App\Enums\FirmUserRole;
use App\Enums\TaskWorkCategory;
use App\Filament\Firm\Resources\TaskCategoryRoleExpectationResource;
use App\Models\Firm;
use App\Models\TaskCategoryRoleExpectation;
use App\Services\Leverage\StaffingPolicyService;
use App\Services\TenantContextService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * CreateTaskCategoryRoleExpectation — routes through
 * StaffingPolicyService::setExpectation(), never a bare Eloquent save,
 * mirroring CreateMatterBudgetTemplate's own established precedent.
 */
class CreateTaskCategoryRoleExpectation extends CreateRecord
{
    protected static string $resource = TaskCategoryRoleExpectationResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $firmUser = Auth::user()?->activeFirmUser();

        abort_unless($firmUser !== null, 403);

        return app(TenantContextService::class)->runWithFirmContext(
            (int) $firmUser->firm_id,
            function () use ($data, $firmUser): TaskCategoryRoleExpectation {
                $firm = Firm::query()->findOrFail($firmUser->firm_id);

                return app(StaffingPolicyService::class)->setExpectation(
                    firm: $firm,
                    actor: $firmUser,
                    category: TaskWorkCategory::from($data['task_category']),
                    recommendedRoles: array_map(fn (string $r) => FirmUserRole::from($r), $data['recommended_roles']),
                    notes: $data['notes'] ?? null,
                );
            },
        );
    }
}
