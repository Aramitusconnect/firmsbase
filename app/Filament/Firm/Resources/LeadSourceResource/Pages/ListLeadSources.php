<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\LeadSourceResource\Pages;

use App\Filament\Firm\Resources\LeadSourceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLeadSources extends ListRecords
{
    protected static string $resource = LeadSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
