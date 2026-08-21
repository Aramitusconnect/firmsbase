<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ChartOfAccountResource\Pages;

use App\Filament\Firm\Resources\ChartOfAccountResource;
use App\Filament\Firm\Resources\ChartOfAccountResource\Actions\DeactivateChartOfAccountAction;
use Filament\Resources\Pages\ViewRecord;

class ViewChartOfAccount extends ViewRecord
{
    protected static string $resource = ChartOfAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeactivateChartOfAccountAction::make(),
        ];
    }
}
