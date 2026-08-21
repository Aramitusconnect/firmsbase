<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\SignatureRequestResource\Pages;

use App\Filament\Firm\Resources\SignatureRequestResource;
use App\Filament\Firm\Resources\SignatureRequestResource\Actions\CreateSignatureRequestAction;
use Filament\Resources\Pages\ListRecords;

class ListSignatureRequests extends ListRecords
{
    protected static string $resource = SignatureRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateSignatureRequestAction::make(),
        ];
    }
}
