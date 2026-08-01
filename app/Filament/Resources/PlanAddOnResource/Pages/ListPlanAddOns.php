<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlanAddOnResource\Pages;

use App\Filament\Actions\Platform\AddPlanModuleAction;
use App\Filament\Resources\PlanAddOnResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListPlanAddOns — FIRMSVAULT STAGING ADMIN STABILIZATION: registers
 * the one supported way to attach a module to a plan, AddPlanModuleAction
 * (a purpose-built header action routing through PlanModuleService::
 * addModule(), not a bare Eloquent create form — see that action's own
 * docblock). Per-record mutations (Enable/Disable/Retire) remain list
 * row actions and View page header actions.
 */
class ListPlanAddOns extends ListRecords
{
    protected static string $resource = PlanAddOnResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AddPlanModuleAction::make(),
        ];
    }
}
