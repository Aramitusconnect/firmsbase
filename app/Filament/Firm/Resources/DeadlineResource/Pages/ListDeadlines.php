<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\DeadlineResource\Pages;

use App\Filament\Firm\Resources\DeadlineResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDeadlines extends ListRecords
{
    protected static string $resource = DeadlineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('+ Add Deadline'),
        ];
    }
}
