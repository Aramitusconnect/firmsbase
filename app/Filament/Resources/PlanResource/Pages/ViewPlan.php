<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlanResource\Pages;

use App\Enums\BillingInterval;
use App\Enums\PlanStatus;
use App\Filament\Actions\Platform\ActivatePlanAction;
use App\Filament\Actions\Platform\ArchivePlanAction;
use App\Filament\Resources\PlanResource;
use App\Support\MoneyDisplay;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * ViewPlan — the standard Filament ViewRecord page (plans carries no
 * RLS, ordinary {record} route-model-binding by uuid). Activate/Archive
 * live here as header actions, mirroring ViewPlatformAdministrator's
 * "mutations live on the View page" convention.
 */
class ViewPlan extends ViewRecord
{
    protected static string $resource = PlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActivatePlanAction::make(),
            ArchivePlanAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Plan')
                ->columns(2)
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (PlanStatus $state): string => Str::headline($state->value))
                        ->color(fn (PlanStatus $state): string => match ($state) {
                            PlanStatus::Active => 'success',
                            PlanStatus::Draft => 'gray',
                            PlanStatus::Archived => 'danger',
                        }),
                    TextEntry::make('price_cents')
                        ->label('Price')
                        ->formatStateUsing(fn (?int $state): string => MoneyDisplay::fromCents($state)),
                    TextEntry::make('billing_interval')
                        ->label('Billing interval')
                        ->formatStateUsing(fn (BillingInterval $state): string => Str::headline($state->value)),
                    TextEntry::make('support_access_level')
                        ->label('Support access level')
                        ->formatStateUsing(fn (string $state): string => Str::headline($state)),
                    TextEntry::make('trial_days')->label('Trial days'),
                    IconEntry::make('trial_requires_card')->label('Trial requires card')->boolean(),
                    IconEntry::make('is_active')->label('Active')->boolean(),
                    TextEntry::make('created_at')->label('Created')->dateTime(),
                ]),
        ]);
    }
}
