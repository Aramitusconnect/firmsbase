<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportCaseResource\Pages;

use App\Filament\Resources\SupportCaseResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListSupportCases — no header actions: administrative oversight over
 * data created through the existing RequestSupportAccessAction flow,
 * not a data-entry form.
 */
class ListSupportCases extends ListRecords
{
    protected static string $resource = SupportCaseResource::class;
}
