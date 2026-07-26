<?php

declare(strict_types=1);

namespace App\Filament\Resources\FailedPaymentResource\Pages;

use App\Filament\Resources\FailedPaymentResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListFailedPayments — no header actions of any kind. This is a
 * strictly read-only oversight view — see FailedPaymentResource's own
 * docblock for the full "why no Retry/Waive action exists" reasoning.
 */
class ListFailedPayments extends ListRecords
{
    protected static string $resource = FailedPaymentResource::class;
}
