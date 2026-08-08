<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ContactResource\Pages;

use App\Filament\Firm\Resources\ContactResource;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewContact extends ViewRecord
{
    protected static string $resource = ContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Contact')
                ->columns(2)
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('company')->placeholder('—'),
                    TextEntry::make('email')->placeholder('—'),
                    TextEntry::make('phone')->placeholder('—'),
                    TextEntry::make('role')->placeholder('—'),
                    TextEntry::make('client.display_name')->label('Linked Client')->placeholder('—'),
                    TextEntry::make('created_at')->dateTime(),
                ]),
        ]);
    }
}
