<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TimeEntryResource\Pages;

use App\Filament\Firm\Resources\TimeEntryResource;
use App\Filament\Firm\Resources\TimeEntryResource\Actions\StartTimerAction;
use App\Filament\Firm\Resources\TimeEntryResource\Actions\StopTimerAction;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTimeEntries extends ListRecords
{
    protected static string $resource = TimeEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            StartTimerAction::make(),
            StopTimerAction::make(),
            CreateAction::make()->label('+ Add Time Entry'),
        ];
    }
}
