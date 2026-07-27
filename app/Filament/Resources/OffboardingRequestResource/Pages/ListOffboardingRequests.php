<?php

declare(strict_types=1);

namespace App\Filament\Resources\OffboardingRequestResource\Pages;

use App\Filament\Resources\OffboardingRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListOffboardingRequests extends ListRecords
{
    protected static string $resource = OffboardingRequestResource::class;
}
