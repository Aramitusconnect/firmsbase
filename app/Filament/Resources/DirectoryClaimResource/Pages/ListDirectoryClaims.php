<?php

declare(strict_types=1);

namespace App\Filament\Resources\DirectoryClaimResource\Pages;

use App\Filament\Resources\DirectoryClaimResource;
use Filament\Resources\Pages\ListRecords;

class ListDirectoryClaims extends ListRecords
{
    protected static string $resource = DirectoryClaimResource::class;
}
