<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TrustLedgerResource\Pages;

use App\Filament\Firm\Resources\TrustLedgerResource;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\OpenTrustLedgerAction;
use Filament\Resources\Pages\ListRecords;

class ListTrustLedgers extends ListRecords
{
    protected static string $resource = TrustLedgerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            OpenTrustLedgerAction::make(),
        ];
    }
}
