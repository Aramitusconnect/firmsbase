<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformRefundResource\Pages;

use App\Filament\Resources\PlatformRefundResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListPlatformRefunds — no header actions of any kind. Strictly
 * read-only oversight — see PlatformRefundResource's own docblock.
 *
 * Billing & Commercial Control Plane pass: getSubheading() states both
 * limitations persistently (refund execution, and the absent Credit
 * domain) rather than only in the empty state, which an operator with
 * refund rows never sees. It also names the Credit/Refund distinction
 * outright — these are financially different operations and the
 * console must not let one stand in for the other.
 */
class ListPlatformRefunds extends ListRecords
{
    protected static string $resource = PlatformRefundResource::class;

    public function getSubheading(): ?string
    {
        return 'Read-only. Refunds return money already collected through a payment gateway; no production-capable '.
            'gateway is configured, so refunds cannot be issued or processed from this console. Credits — reducing '.
            'what a customer owes without moving money — are a separate concept and are not implemented anywhere '.
            'in this codebase: there is no Credit model, table, or service, so no credit balance is shown here.';
    }
}
