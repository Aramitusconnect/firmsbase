<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TimeEntryResource\Pages;

use App\Filament\Firm\Resources\TimeEntryResource;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewTimeEntry extends ViewRecord
{
    protected static string $resource = TimeEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Time Entry')
                ->columns(2)
                ->schema([
                    TextEntry::make('matter.stage')->label('Matter')->placeholder('—'),
                    TextEntry::make('client.display_name')->label('Client')->placeholder('—'),
                    TextEntry::make('user.name')->label('Biller')->placeholder('—'),
                    TextEntry::make('worked_on')->label('Worked On')->date(),
                    TextEntry::make('seconds')
                        ->label('Duration')
                        ->formatStateUsing(fn (int $state): string => TimeEntryResource::formatDuration($state)),
                    TextEntry::make('is_billable')->label('Billable')->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('billing_rate_cents_snapshot')
                        ->label('Billing Rate (snapshot)')
                        ->placeholder('—')
                        ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : '$'.number_format($state / 100, 2)),
                    TextEntry::make('approvedBy.name')->label('Approved By')->placeholder('—'),
                    TextEntry::make('approved_at')->dateTime()->placeholder('—'),
                    TextEntry::make('rejected_reason')->label('Rejected Reason')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('description')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('created_at')->dateTime(),
                ]),
        ]);
    }
}
