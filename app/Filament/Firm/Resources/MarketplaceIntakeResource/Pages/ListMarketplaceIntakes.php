<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MarketplaceIntakeResource\Pages;

use App\Filament\Firm\Resources\MarketplaceIntakeResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListMarketplaceIntakes — the Firm lead queue. No header actions —
 * a MarketplaceIntake is never Firm-created (see the Resource's own
 * docblock).
 */
class ListMarketplaceIntakes extends ListRecords
{
    protected static string $resource = MarketplaceIntakeResource::class;
}
