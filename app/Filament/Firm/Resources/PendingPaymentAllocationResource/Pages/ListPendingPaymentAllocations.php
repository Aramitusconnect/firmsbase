<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\PendingPaymentAllocationResource\Pages;

use App\Filament\Firm\Resources\PendingPaymentAllocationResource;
use Filament\Resources\Pages\ListRecords;

class ListPendingPaymentAllocations extends ListRecords
{
    protected static string $resource = PendingPaymentAllocationResource::class;
}
