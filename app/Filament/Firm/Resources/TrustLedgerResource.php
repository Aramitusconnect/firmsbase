<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Enums\TrustLedgerStatus;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\CloseTrustLedgerAction;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\FreezeTrustLedgerAction;
use App\Filament\Firm\Resources\TrustLedgerResource\Pages\ListTrustLedgers;
use App\Filament\Firm\Resources\TrustLedgerResource\Pages\ViewTrustLedger;
use App\Filament\Firm\Resources\TrustLedgerResource\RelationManagers\EntriesRelationManager;
use App\Filament\Firm\Resources\TrustLedgerResource\RelationManagers\RefundRequestsRelationManager;
use App\Filament\Firm\Resources\TrustLedgerResource\RelationManagers\TransferRequestsRelationManager;
use App\Models\Client;
use App\Models\TrustLedger;
use App\Services\TrustAccessPolicyService;
use App\Services\TrustEligibilityService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * TrustLedgerResource — Firm Feature Manifest §7 (Trust/IOLTA). List +
 * View pages ONLY, same shape/rationale as TrustAccountResource (see
 * that class's own docblock — this one is not repeated verbatim here).
 *
 * The "nested under Client" structural relationship this module's
 * governing prompt asks about is real (TrustLedger::client_id is a
 * genuine FK) and is honored two ways: a `client_id` SelectFilter on
 * this Resource's own table below, AND TrustAccountResource's own
 * LedgersRelationManager tab, which lists every ledger under its
 * owning TrustAccount and links out to this Resource's ViewRecord page
 * for the full deposit/transfer/refund/adjustment/freeze/close Action
 * set. A literal ClientResource RelationManager was deliberately not
 * added on top of that — TrustAccountResource's own relation manager
 * already covers the "browse ledgers from a natural parent" need
 * without a second, redundant nesting path.
 */
class TrustLedgerResource extends Resource
{
    protected static ?string $model = TrustLedger::class;

    protected static ?string $slug = 'trust-ledgers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $navigationLabel = 'Trust Ledgers';

    protected static string|\UnitEnum|null $navigationGroup = 'Trust Accounting';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'id';

    public static function canAccess(): bool
    {
        return parent::canAccess() && static::isFirmTrustEligible();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && static::isFirmTrustEligible();
    }

    private static function isFirmTrustEligible(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        return app(TrustEligibilityService::class)->isEligible($firmUser->firm)
            && app(TrustAccessPolicyService::class)->canRequest($firmUser->role);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('client.display_name')->label('Client')->searchable(),
                TextColumn::make('trustAccount.account_name')->label('Trust Account')->placeholder('—'),
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
                    ->formatStateUsing(fn ($state): string => '$'.number_format(((int) ($state ?? 0)) / 100, 2)),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(fn (): array => collect(TrustLedgerStatus::cases())->mapWithKeys(fn ($case) => [$case->value => (string) str($case->value)->headline()])->all()),
                SelectFilter::make('client_id')
                    ->label('Client')
                    ->options(fn (): array => Client::query()->orderBy('display_name')->pluck('display_name', 'id')->all())
                    ->searchable(),
            ])
            ->recordActions([
                FreezeTrustLedgerAction::make(),
                CloseTrustLedgerAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            EntriesRelationManager::class,
            TransferRequestsRelationManager::class,
            RefundRequestsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrustLedgers::route('/'),
            'view' => ViewTrustLedger::route('/{record}'),
        ];
    }
}
