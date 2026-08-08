<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\InvoiceResource\Pages;

use App\Filament\Firm\Resources\InvoiceResource;
use App\Filament\Firm\Resources\InvoiceResource\Actions\CreateFlatFeeInvoiceAction;
use App\Filament\Firm\Resources\InvoiceResource\Actions\DraftFromTimeEntriesAction;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DraftFromTimeEntriesAction::make(),
            CreateFlatFeeInvoiceAction::make(),
        ];
    }
}
