<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformFleetMigrationRunResource\Pages;

use App\Filament\Actions\Platform\CreateFleetMigrationRunAction;
use App\Filament\Resources\PlatformFleetMigrationRunResource;
use App\Services\FleetMigrationSafetyService;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * ListPlatformFleetMigrationRuns — header action:
 * CreateFleetMigrationRunAction, the sole way a new run is created
 * from this console.
 *
 * Operations Control Plane addition: the rehearsal-only disclosure
 * now appears on the LIST page, not only on the run detail page. The
 * list is where an operator forms their impression of what this tool
 * is — a table of runs with a green "Completed" badge reads as a
 * fleet rollout history unless the page says otherwise before they
 * scroll.
 */
class ListPlatformFleetMigrationRuns extends ListRecords
{
    protected static string $resource = PlatformFleetMigrationRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateFleetMigrationRunAction::make(),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->rehearsalDisclosureSection(),
            ...parent::content($schema)->getComponents(),
        ]);
    }

    private function rehearsalDisclosureSection(): Section
    {
        $safety = app(FleetMigrationSafetyService::class);

        return Section::make('Rehearsal Tool — No Migration Is Ever Executed')
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->schema([
                Text::make($safety->disclosure())->color('danger'),
                Text::make(
                    'Missing safety controls: '.
                    collect($safety->missingControls())->pluck('control')->implode(', ').
                    '. These are listed so the gap between this rehearsal tool and a production fleet orchestrator '.
                    'stays explicit.'
                )->color('gray'),
            ]);
    }
}
