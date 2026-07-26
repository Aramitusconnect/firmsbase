<?php

declare(strict_types=1);

namespace App\Filament\Resources\ConflictResource\Pages;

use App\Filament\Resources\ConflictResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

/**
 * ListConflicts — no header actions, and (structurally, not merely by
 * omission — see ConflictResource's own docblock) no mutating action
 * anywhere in this resource. The banner below makes the read-only,
 * monitoring-only nature of this page visually unambiguous, in addition
 * to the table's own emptyStateDescription().
 */
class ListConflicts extends ListRecords
{
    protected static string $resource = ConflictResource::class;

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Text::make('Monitoring only. Conflicts cannot be resolved from this console — resolution requires the normal FirmUser dual-approval workflow inside the firm panel, which this Admin console structurally cannot perform (it has no second, independent FirmUser identity to supply).')
                ->color('gray'),
            EmbeddedTable::make(),
        ]);
    }
}
