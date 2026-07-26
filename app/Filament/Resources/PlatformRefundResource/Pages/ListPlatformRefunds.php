<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformRefundResource\Pages;

use App\Filament\Resources\PlatformRefundResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListPlatformRefunds — no header actions of any kind. Strictly
 * read-only oversight — see PlatformRefundResource's own docblock.
 */
class ListPlatformRefunds extends ListRecords
{
    protected static string $resource = PlatformRefundResource::class;
}
