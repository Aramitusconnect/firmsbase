<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\PlaidItemResource\Pages;

use App\Filament\Firm\Resources\PlaidItemResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListPlaidItems — FirmsVault Live Integrations, Checkpoint 4 ("Plaid
 * financial evidence add-on"). List-only — no CreateAction (a Plaid
 * connection is always initiated from the Client Portal's Link flow,
 * never a firm-panel form), mirroring `ListFirmIntegrations`' own
 * Action-based, never Form-backed discipline.
 */
class ListPlaidItems extends ListRecords
{
    protected static string $resource = PlaidItemResource::class;
}
