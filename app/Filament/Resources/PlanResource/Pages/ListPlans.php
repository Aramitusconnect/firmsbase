<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlanResource\Pages;

use App\Filament\Actions\Platform\CreatePlanAction;
use App\Filament\Resources\PlanResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListPlans — FIRMSVAULT STAGING ADMIN STABILIZATION: registers the
 * one supported way to create a new Plan, CreatePlanAction (a
 * purpose-built header action routing through PlanService, not
 * Filament's generic CreateAction — see that action's own docblock).
 * Per-record mutations (Activate/Archive/Edit) remain list row actions
 * and View page header actions.
 */
class ListPlans extends ListRecords
{
    protected static string $resource = PlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreatePlanAction::make(),
        ];
    }
}
