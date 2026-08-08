<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\FirmLeadResource\Pages;

use App\Filament\Firm\Resources\FirmLeadResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFirmLeads extends ListRecords
{
    protected static string $resource = FirmLeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('+ Add Lead'),
        ];
    }
}
