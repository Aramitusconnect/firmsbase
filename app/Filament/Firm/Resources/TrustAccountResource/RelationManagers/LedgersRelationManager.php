<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TrustAccountResource\RelationManagers;

use App\Filament\Firm\Resources\TrustLedgerResource;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\OpenTrustLedgerAction;
use App\Models\TrustAccount;
use App\Models\TrustLedger;
use App\Services\TrustAccessPolicyService;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * LedgersRelationManager — "Ledgers" tab on ViewTrustAccount, listing
 * this account's TrustLedger rows (`TrustAccount::ledgers()`, a real,
 * already-defined HasMany). Strictly read-only here — deposit/transfer/
 * refund/adjustment/freeze/close actions for a given ledger all live on
 * TrustLedgerResource's own ViewTrustLedger page (linked out via the
 * "Manage" row action below), mirroring PaymentsRelationManager's own
 * "link out to the owning resource's real Filament page" pattern rather
 * than duplicating that page's Actions here.
 */
class LedgersRelationManager extends RelationManager
{
    protected static string $relationship = 'ledgers';

    protected static ?string $title = 'Ledgers';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null || ! $ownerRecord instanceof TrustAccount || (int) $firmUser->firm_id !== (int) $ownerRecord->firm_id) {
            return false;
        }

        return app(TrustAccessPolicyService::class)->canRequest($firmUser->role);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('client.display_name')->label('Client')->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'active' => 'success',
                        'frozen' => 'warning',
                        'closed' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('balance.balance_cents')
                    ->label('Balance')
                    ->formatStateUsing(fn ($state): string => '$'.number_format(((int) $state) / 100, 2)),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                OpenTrustLedgerAction::make(),
            ])
            ->recordActions([
                Action::make('manageLedger')
                    ->label('Manage')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->url(fn (TrustLedger $record): string => TrustLedgerResource::getUrl('view', ['record' => $record])),
            ])
            ->toolbarActions([]);
    }
}
