<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\DeadlineResource\Pages;

use App\Filament\Firm\Resources\DeadlineResource;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewDeadline extends ViewRecord
{
    protected static string $resource = DeadlineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Deadline')
                ->columns(2)
                ->schema([
                    TextEntry::make('title')->columnSpanFull(),
                    TextEntry::make('deadline_type')->label('Type'),
                    TextEntry::make('matter.stage')->label('Matter')->placeholder('—'),
                    TextEntry::make('jurisdiction')->placeholder('—'),
                    TextEntry::make('source')->placeholder('—'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('due_at')->dateTime(),
                    TextEntry::make('reminder_offsets_days')->label('Reminder Offsets')->placeholder('—'),
                    TextEntry::make('completed_at')->dateTime()->placeholder('—'),
                    TextEntry::make('cancelled_at')->dateTime()->placeholder('—'),
                    TextEntry::make('created_at')->dateTime(),
                ]),
        ]);
    }
}
