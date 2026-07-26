<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformInvoiceResource\Pages;

use App\Filament\Resources\PlatformInvoiceResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListPlatformInvoices — no header actions: invoices are created
 * exclusively through the normal billing workflow
 * (PlatformInvoiceService::createDraftInvoice()), never a Filament
 * data-entry form. Finalize/Void live on the View page only, mirroring
 * PlatformAdministratorResource's own "mutations happen on the View
 * page, per-record, not here" convention.
 */
class ListPlatformInvoices extends ListRecords
{
    protected static string $resource = PlatformInvoiceResource::class;
}
