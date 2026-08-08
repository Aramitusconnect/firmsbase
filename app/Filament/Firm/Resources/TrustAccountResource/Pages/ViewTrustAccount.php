<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TrustAccountResource\Pages;

use App\Filament\Firm\Resources\TrustAccountResource;
use App\Filament\Firm\Resources\TrustAccountResource\Actions\CloseTrustAccountAction;
use App\Filament\Firm\Resources\TrustAccountResource\Actions\SuspendTrustAccountAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * ViewTrustAccount — read-only Infolist only (no `form()` on
 * TrustAccountResource at all — nothing here is editable). Suspend/
 * Close are the same two Actions available as table row actions on
 * ListTrustAccounts, exposed here as header actions too.
 */
class ViewTrustAccount extends ViewRecord
{
    protected static string $resource = TrustAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SuspendTrustAccountAction::make(),
            CloseTrustAccountAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Trust Account')
                ->columns(2)
                ->schema([
                    TextEntry::make('account_name')->label('Account Name'),
                    TextEntry::make('bank_name_reference')->label('Bank Reference')->placeholder('—'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state)
                        ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                            'active' => 'success',
                            'suspended' => 'warning',
                            'closed' => 'gray',
                            default => 'gray',
                        }),
                    TextEntry::make('opened_at')->label('Opened At')->dateTime()->placeholder('—'),
                    TextEntry::make('created_at')->label('Created At')->dateTime(),
                ]),
        ]);
    }
}
