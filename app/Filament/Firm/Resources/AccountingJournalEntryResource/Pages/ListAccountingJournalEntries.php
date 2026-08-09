<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\AccountingJournalEntryResource\Pages;

use App\Filament\Firm\Resources\AccountingJournalEntryResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListAccountingJournalEntries — no header actions: there is no way to
 * create a row here outside the real business-event flows, each
 * already reached from its own domain page/action elsewhere.
 */
class ListAccountingJournalEntries extends ListRecords
{
    protected static string $resource = AccountingJournalEntryResource::class;
}
