<?php

declare(strict_types=1);

namespace App\Filament\Resources\TrialRequestResource\Pages;

use App\Filament\Resources\TrialRequestResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListTrialRequests — no header actions: no Create form (see
 * TrialRequestResource's own docblock for why). Mutations
 * (Provision/Activate/Convert/Expire) happen per-record, both as list
 * row actions and View page header actions.
 */
class ListTrialRequests extends ListRecords
{
    protected static string $resource = TrialRequestResource::class;
}
