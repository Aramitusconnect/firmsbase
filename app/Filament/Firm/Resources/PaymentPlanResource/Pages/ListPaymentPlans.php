<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\PaymentPlanResource\Pages;

use App\Filament\Firm\Resources\PaymentPlanResource;
use App\Filament\Firm\Resources\PaymentPlanResource\Actions\CreatePaymentPlanAction;
use Filament\Resources\Pages\ListRecords;

class ListPaymentPlans extends ListRecords
{
    protected static string $resource = PaymentPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreatePaymentPlanAction::make(),
        ];
    }
}
