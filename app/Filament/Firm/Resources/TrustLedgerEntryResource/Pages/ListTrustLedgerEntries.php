<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TrustLedgerEntryResource\Pages;

use App\Filament\Firm\Resources\TrustLedgerEntryResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListTrustLedgerEntries — no header actions at all: there is no way to
 * create a trust_ledger_entries row outside the deposit/transfer/
 * refund/adjustment/chargeback-reversal flows, each already a dedicated
 * Action on TrustLedgerResource/TrustLedgerEntryResource elsewhere.
 */
class ListTrustLedgerEntries extends ListRecords
{
    protected static string $resource = TrustLedgerEntryResource::class;
}
