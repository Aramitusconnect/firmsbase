<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\CalendarEventResource\Pages;

use App\Filament\Firm\Resources\CalendarEventResource;
use App\Services\TaskCrudAccessPolicyService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListCalendarEvents extends ListRecords
{
    protected static string $resource = CalendarEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('+ Add Calendar Event')
                ->visible(function (): bool {
                    $firmUser = Auth::user()?->activeFirmUser();

                    return $firmUser !== null
                        && app(TaskCrudAccessPolicyService::class)->canManageTask($firmUser->role);
                }),
        ];
    }
}
