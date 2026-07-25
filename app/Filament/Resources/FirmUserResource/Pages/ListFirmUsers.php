<?php

declare(strict_types=1);

namespace App\Filament\Resources\FirmUserResource\Pages;

use App\Filament\Resources\FirmUserResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListFirmUsers — no header actions: administrative oversight view
 * only, no Create/Edit forms (mirrors the Firm panel's FirmIntegration Resource's and
 * ListFirms's own "no Create/Edit forms" ruling).
 */
class ListFirmUsers extends ListRecords
{
    protected static string $resource = FirmUserResource::class;
}
