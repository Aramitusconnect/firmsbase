<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlanAddOnResource\Pages;

use App\Filament\Resources\PlanAddOnResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListPlanAddOns — no header actions: no Create form (see
 * PlanAddOnResource's own docblock for why). Mutations
 * (Enable/Disable/Retire) happen per-record, both as list row actions
 * and View page header actions.
 */
class ListPlanAddOns extends ListRecords
{
    protected static string $resource = PlanAddOnResource::class;
}
