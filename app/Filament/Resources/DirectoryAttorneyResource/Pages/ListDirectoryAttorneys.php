<?php

declare(strict_types=1);

namespace App\Filament\Resources\DirectoryAttorneyResource\Pages;

use App\Filament\Resources\DirectoryAttorneyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDirectoryAttorneys extends ListRecords
{
    protected static string $resource = DirectoryAttorneyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add Attorney'),
        ];
    }
}
