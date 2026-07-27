<?php

declare(strict_types=1);

namespace App\Filament\Resources\LegalHoldResource\Pages;

use App\Filament\Actions\Platform\PlaceLegalHoldAction;
use App\Filament\Resources\LegalHoldResource;
use Filament\Resources\Pages\ListRecords;

class ListLegalHolds extends ListRecords
{
    protected static string $resource = LegalHoldResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PlaceLegalHoldAction::make(),
        ];
    }
}
