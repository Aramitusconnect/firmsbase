<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportSessionResource\Pages;

use App\Filament\Resources\SupportSessionResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListSupportSessions — no header actions: administrative oversight
 * over sessions created through the existing
 * EnterSupportAccessSessionAction flow, not a data-entry form.
 */
class ListSupportSessions extends ListRecords
{
    protected static string $resource = SupportSessionResource::class;
}
