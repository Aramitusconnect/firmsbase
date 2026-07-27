<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeletionRequestResource\Pages;

use App\Filament\Resources\DeletionRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListDeletionRequests extends ListRecords
{
    protected static string $resource = DeletionRequestResource::class;
}
