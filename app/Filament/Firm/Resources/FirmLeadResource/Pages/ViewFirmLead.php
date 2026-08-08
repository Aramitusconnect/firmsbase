<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\FirmLeadResource\Pages;

use App\Filament\Firm\Resources\FirmLeadResource;
use App\Filament\Firm\Resources\FirmLeadResource\Actions\ConvertLeadToClientAction;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewFirmLead extends ViewRecord
{
    protected static string $resource = FirmLeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ConvertLeadToClientAction::make(),
            EditAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Lead')
                ->columns(2)
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('email')->placeholder('—'),
                    TextEntry::make('phone')->placeholder('—'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state),
                    TextEntry::make('leadSource.name')->label('Source')->placeholder('—'),
                    TextEntry::make('practiceAreaInterest.name')->label('Practice Area')->placeholder('—'),
                    TextEntry::make('assignedTo.name')->label('Assigned To')->placeholder('—'),
                    TextEntry::make('created_at')->dateTime(),
                ]),

            Section::make('Conversion')
                ->columns(2)
                ->schema([
                    TextEntry::make('convertedClient.display_name')->label('Converted Client')->placeholder('Not yet converted'),
                    TextEntry::make('converted_at')->dateTime()->placeholder('—'),
                ]),
        ]);
    }
}
