<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\AutomationActionExecutionResource\Pages;

use App\Filament\Firm\Resources\AutomationActionExecutionResource;
use Filament\Resources\Pages\ListRecords;

class ListAutomationActionExecutions extends ListRecords
{
    protected static string $resource = AutomationActionExecutionResource::class;
}
