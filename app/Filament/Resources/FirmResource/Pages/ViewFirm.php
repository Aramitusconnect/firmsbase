<?php

declare(strict_types=1);

namespace App\Filament\Resources\FirmResource\Pages;

use App\Filament\Resources\FirmResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * ViewFirm — read-only detail view over Firm's real fillable columns
 * only. No mutating action is registered here (no Edit page, no header
 * Action) — see FirmResource's own docblock.
 */
class ViewFirm extends ViewRecord
{
    protected static string $resource = FirmResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Firm')
                ->columns(2)
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('legal_name')->placeholder('—'),
                    TextEntry::make('activation_status')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state),
                    TextEntry::make('customer_type')
                        ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state),
                    TextEntry::make('deployment_mode')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state),
                    TextEntry::make('primary_country')->label('Country')->placeholder('—'),
                    TextEntry::make('primary_state')->label('State/Province')->placeholder('—'),
                    TextEntry::make('default_timezone')->label('Timezone')->placeholder('—'),
                    TextEntry::make('default_currency')->label('Currency')->placeholder('—'),
                    TextEntry::make('data_region')->placeholder('—'),
                    TextEntry::make('created_at')->dateTime(),
                ]),
        ]);
    }
}
