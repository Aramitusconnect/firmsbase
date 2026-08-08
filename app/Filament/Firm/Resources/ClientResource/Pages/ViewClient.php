<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ClientResource\Pages;

use App\Filament\Firm\Resources\ClientResource;
use App\Models\Client;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewClient extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Client Profile')
                ->columns(2)
                ->schema([
                    TextEntry::make('display_name')->label('Name'),
                    TextEntry::make('legal_name')->placeholder('—'),
                    TextEntry::make('email')->placeholder('—'),
                    TextEntry::make('phone')->placeholder('—'),
                    TextEntry::make('preferred_language')->placeholder('—'),
                    TextEntry::make('preferred_timezone')->placeholder('—'),
                    TextEntry::make('portal_status')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state),
                    TextEntry::make('created_at')->dateTime(),
                ]),

            Section::make('Origin')
                ->columns(2)
                ->schema([
                    TextEntry::make('originating_lead')
                        ->label('Converted from Lead')
                        ->state(fn (Client $record) => $record->firmLeadsConverted()->first()?->name)
                        ->placeholder('—'),
                    TextEntry::make('createdBy.name')->label('Added by')->placeholder('—'),
                ]),
        ]);
    }
}
