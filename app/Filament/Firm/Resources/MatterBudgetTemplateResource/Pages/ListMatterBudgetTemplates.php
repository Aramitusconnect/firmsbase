<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterBudgetTemplateResource\Pages;

use App\Filament\Firm\Resources\MatterBudgetTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMatterBudgetTemplates extends ListRecords
{
    protected static string $resource = MatterBudgetTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
