<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlanResource\Pages;

use App\Filament\Resources\PlanResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListPlans — no header actions: no Create form (see PlanResource's own
 * docblock for why). Mutations (Activate/Archive) happen per-record,
 * both as list row actions and View page header actions.
 */
class ListPlans extends ListRecords
{
    protected static string $resource = PlanResource::class;
}
