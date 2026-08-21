<?php

declare(strict_types=1);

namespace App\Filament\ClientPortal\Resources\InvoiceResource\Pages;

use App\Filament\ClientPortal\Resources\InvoiceResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListInvoices (Client Portal) — Mission 4 (Client Portal Activation),
 * finding 4.6. No header actions — read-only visibility only.
 */
class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
