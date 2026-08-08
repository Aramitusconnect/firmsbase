<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\DocumentRequestResource\Pages;

use App\Filament\Firm\Resources\DocumentRequestResource;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * ViewDocumentRequest — read-only Infolist for the parent request's own
 * fields; the requested items themselves (with every status-transition
 * Action) are shown below via ItemsRelationManager (see
 * DocumentRequestResource::getRelations()), never duplicated here.
 */
class ViewDocumentRequest extends ViewRecord
{
    protected static string $resource = DocumentRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Document Request')
                ->columns(2)
                ->schema([
                    TextEntry::make('client.display_name')->label('Client')->placeholder('—'),
                    TextEntry::make('matter.stage')->label('Matter')->placeholder('—'),
                    TextEntry::make('title'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state)
                        ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                            'fulfilled' => 'success',
                            'partially_fulfilled' => 'warning',
                            'cancelled' => 'gray',
                            default => 'info',
                        }),
                    TextEntry::make('due_at')->dateTime()->placeholder('—'),
                    TextEntry::make('createdBy.name')->label('Created By')->placeholder('—'),
                    TextEntry::make('instructions')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('created_at')->dateTime(),
                ]),
        ]);
    }
}
