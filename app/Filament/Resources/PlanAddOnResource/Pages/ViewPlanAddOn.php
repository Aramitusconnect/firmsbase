<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlanAddOnResource\Pages;

use App\Enums\PlanModuleStatus;
use App\Filament\Actions\Platform\RetirePlanModuleAction;
use App\Filament\Actions\Platform\SetPlanModuleEnabledAction;
use App\Filament\Resources\PlanAddOnResource;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * ViewPlanAddOn — the standard Filament ViewRecord page (plan_modules
 * carries no RLS, ordinary {record} route-model-binding by uuid).
 * Enable/Disable and Retire live here as header actions.
 *
 * The catalog-only-effect disclosure (see PlanAddOnResource's own
 * docblock) is repeated once more here, on the record's own detail
 * page, since this is the page an admin most plausibly reads carefully
 * before acting.
 */
class ViewPlanAddOn extends ViewRecord
{
    protected static string $resource = PlanAddOnResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SetPlanModuleEnabledAction::make(),
            RetirePlanModuleAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Add-on')
                ->columns(2)
                ->schema([
                    TextEntry::make('plan.name')->label('Plan')->placeholder('—'),
                    TextEntry::make('module_code')->label('Module code')->fontFamily('mono'),
                    IconEntry::make('enabled')->boolean(),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (PlanModuleStatus $state): string => Str::headline($state->value))
                        ->color(fn (PlanModuleStatus $state): string => match ($state) {
                            PlanModuleStatus::Active => 'success',
                            PlanModuleStatus::Retired => 'danger',
                        }),
                    TextEntry::make('created_at')->label('Created')->dateTime(),
                ]),
            Section::make('Effect of changes')
                ->schema([
                    Text::make('Changes here affect the plan catalog only. They do not immediately change any firm\'s active entitlements — a firm only picks up this plan\'s current add-on configuration the next time its license is (re-)assigned this plan.'),
                ]),
        ]);
    }
}
