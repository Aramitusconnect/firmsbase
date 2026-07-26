<?php

declare(strict_types=1);

namespace App\Filament\Resources\SyncFailureResource\Pages;

use App\Filament\Resources\SyncFailureResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListSyncFailures — no header actions: cross-firm oversight view only.
 * Route-level authorization is enforced by Filament's own Resource
 * page-access wiring, which calls SyncFailureResource::canAccess() —
 * see that class's own docblock.
 */
class ListSyncFailures extends ListRecords
{
    protected static string $resource = SyncFailureResource::class;
}
