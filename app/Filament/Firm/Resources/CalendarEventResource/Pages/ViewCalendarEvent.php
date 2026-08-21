<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\CalendarEventResource\Pages;

use App\Filament\Firm\Resources\CalendarEventResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewCalendarEvent extends ViewRecord
{
    protected static string $resource = CalendarEventResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Calendar Event')
                ->columns(2)
                ->schema([
                    TextEntry::make('title')->columnSpanFull(),
                    TextEntry::make('event_type')
                        ->label('Type')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state),
                    TextEntry::make('matter.stage')->label('Matter')->placeholder('—'),
                    TextEntry::make('starts_at')->dateTime(),
                    TextEntry::make('ends_at')->dateTime()->placeholder('—'),
                    TextEntry::make('all_day')->label('All Day')->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No'),
                    TextEntry::make('createdBy.name')->label('Created By')->placeholder('—'),
                    TextEntry::make('created_at')->dateTime(),
                ]),
        ]);
    }
}
