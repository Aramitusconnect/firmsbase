<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ExpenseResource\Pages;

use App\Filament\Firm\Resources\ExpenseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('+ Add Expense'),
        ];
    }
}
