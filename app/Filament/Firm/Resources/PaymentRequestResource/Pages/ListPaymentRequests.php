<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\PaymentRequestResource\Pages;

use App\Filament\Firm\Resources\PaymentRequestResource;
use App\Filament\Firm\Resources\PaymentRequestResource\Actions\CreatePaymentRequestAction;
use Filament\Resources\Pages\ListRecords;

class ListPaymentRequests extends ListRecords
{
    protected static string $resource = PaymentRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreatePaymentRequestAction::make(),
        ];
    }
}
