<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TaskCategoryRoleExpectationResource\Pages;

use App\Filament\Firm\Resources\TaskCategoryRoleExpectationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTaskCategoryRoleExpectations extends ListRecords
{
    protected static string $resource = TaskCategoryRoleExpectationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
