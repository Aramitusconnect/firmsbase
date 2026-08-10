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
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * EditTaskCategoryRoleExpectation — routes through
 * StaffingPolicyService::setExpectation()/remove(), never a bare
 * Eloquent save/delete.
 */
class EditTaskCategoryRoleExpectation extends EditRecord
{
    protected static string $resource = TaskCategoryRoleExpectationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->action(function (): void {
                    $firmUser = Auth::user()?->activeFirmUser();

                    abort_unless($firmUser !== null, 403);

                    app(TenantContextService::class)->runWithFirmContext(
                        (int) $firmUser->firm_id,
                        function () use ($firmUser): void {
                            $firm = Firm::query()->findOrFail($firmUser->firm_id);
                            app(StaffingPolicyService::class)->remove($firm, $this->record, $firmUser);
                        },
                    );

                    $this->redirect(static::getResource()::getUrl('index'));
                }),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['recommended_roles'] = $this->record->recommended_roles_json;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
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
