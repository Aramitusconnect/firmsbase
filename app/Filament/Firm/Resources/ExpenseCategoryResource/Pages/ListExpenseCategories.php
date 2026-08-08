<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ExpenseCategoryResource\Pages;

use App\Filament\Firm\Resources\ExpenseCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExpenseCategories extends ListRecords
{
    protected static string $resource = ExpenseCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
