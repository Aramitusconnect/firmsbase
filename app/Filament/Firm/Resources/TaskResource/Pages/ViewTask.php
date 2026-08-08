<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TaskResource\Pages;

use App\Filament\Firm\Resources\TaskResource;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewTask extends ViewRecord
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Task')
                ->columns(2)
                ->schema([
                    TextEntry::make('title')->columnSpanFull(),
                    TextEntry::make('description')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('matter.stage')->label('Matter')->placeholder('—'),
                    TextEntry::make('client.display_name')->label('Client')->placeholder('—'),
                    TextEntry::make('assignedTo.name')->label('Assigned To')->placeholder('—'),
                    TextEntry::make('priority')->badge(),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('due_at')->dateTime()->placeholder('—'),
                    TextEntry::make('completed_at')->dateTime()->placeholder('—'),
                    TextEntry::make('cancelled_at')->dateTime()->placeholder('—'),
                    TextEntry::make('created_at')->dateTime(),
                ]),
        ]);
    }
}
