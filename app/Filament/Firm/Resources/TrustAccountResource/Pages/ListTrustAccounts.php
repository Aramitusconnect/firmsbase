<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TrustAccountResource\Pages;

use App\Filament\Firm\Resources\TrustAccountResource;
use App\Filament\Firm\Resources\TrustAccountResource\Actions\OpenTrustAccountAction;
use Filament\Resources\Pages\ListRecords;

class ListTrustAccounts extends ListRecords
{
    protected static string $resource = TrustAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            OpenTrustAccountAction::make(),
        ];
    }
}
