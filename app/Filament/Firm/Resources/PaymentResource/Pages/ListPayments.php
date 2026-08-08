<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\PaymentResource\Pages;

use App\Filament\Firm\Resources\PaymentResource;
use App\Filament\Firm\Resources\PaymentResource\Actions\RecordPaymentAction;
use Filament\Resources\Pages\ListRecords;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RecordPaymentAction::make(),
        ];
    }
}
