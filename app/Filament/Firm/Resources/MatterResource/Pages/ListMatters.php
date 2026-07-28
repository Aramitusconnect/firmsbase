<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterResource\Pages;

use App\Filament\Firm\Resources\MatterResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListMatters — Checkpoint 4 ("Plaid financial evidence add-on").
 * Searchable matter list (client name, stage — see
 * MatterResource::table()). No header actions — matter creation stays
 * exclusively MatterOpeningService's responsibility, matching
 * ListFirmIntegrations' "no CreateAction" discipline, here with zero
 * replacement actions rather than an Action-based one, since no
 * Checkpoint-4-scoped matter-creation flow exists.
 */
class ListMatters extends ListRecords
{
    protected static string $resource = MatterResource::class;
}
