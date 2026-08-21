<?php

declare(strict_types=1);

namespace App\Filament\ClientPortal\Resources\PaymentPlanResource\Pages;

use App\Filament\ClientPortal\Resources\PaymentPlanResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListPaymentPlans (Client Portal) — PORTAL-003. No header actions —
 * read-only visibility only.
 */
class ListPaymentPlans extends ListRecords
{
    protected static string $resource = PaymentPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
