<?php

declare(strict_types=1);

namespace App\Filament\Resources\FirmResource\Pages;

use App\Filament\Resources\FirmResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListFirms — no header actions: this is an administrative oversight
 * view over data created through normal application flows (firm
 * onboarding), not a data-entry form. No CreateAction, mirroring
 * the Firm panel's FirmIntegration Resource's own "no Create/Edit forms" ruling.
 */
class ListFirms extends ListRecords
{
    protected static string $resource = FirmResource::class;
}
