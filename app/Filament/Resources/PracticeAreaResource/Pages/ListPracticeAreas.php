<?php

declare(strict_types=1);

namespace App\Filament\Resources\PracticeAreaResource\Pages;

use App\Filament\Actions\Platform\CreatePracticeAreaAction;
use App\Filament\Resources\PracticeAreaResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListPracticeAreas — registers CreatePracticeAreaAction (a purpose-
 * built header action routing through PracticeAreaService, not
 * Filament's generic CreateAction). Mirrors ListPlans' exact shape.
 */
class ListPracticeAreas extends ListRecords
{
    protected static string $resource = PracticeAreaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreatePracticeAreaAction::make(),
        ];
    }
}
