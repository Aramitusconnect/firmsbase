<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ChartOfAccountResource\Pages;

use App\Filament\Firm\Resources\ChartOfAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListChartOfAccounts extends ListRecords
{
    protected static string $resource = ChartOfAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
