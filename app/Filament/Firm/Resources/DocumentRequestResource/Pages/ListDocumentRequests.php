<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\DocumentRequestResource\Pages;

use App\Filament\Firm\Resources\DocumentRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDocumentRequests extends ListRecords
{
    protected static string $resource = DocumentRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('+ New Document Request'),
        ];
    }
}
