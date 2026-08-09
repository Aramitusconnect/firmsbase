<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Enums\AccountingJournalSourceType;
use App\Filament\Firm\Resources\AccountingJournalEntryResource\Pages\ListAccountingJournalEntries;
use App\Filament\Firm\Resources\AccountingJournalEntryResource\Pages\ViewAccountingJournalEntry;
use App\Models\AccountingJournalEntry;
use App\Services\AccountingEntitlementPolicyService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * AccountingJournalEntryResource — Phase L. List + View pages ONLY —
 * NO Create/Edit anywhere, mirroring TrustLedgerEntryResource's own
 * shape exactly (the model's own booted() append-only guard is
 * defense in depth, not the only line of defense against a raw
 * unguarded create()). Every entry is posted only through
 * OperatingJournalRecorderService/AccountingJournalPostingService,
 * reached from the real business events (payment applied, trust
 * transfer, expense approved, refund, chargeback) — never from this
 * Resource, which exposes no `form()` at all.
 *
 * `getEloquentQuery()` override for the same reason as
 * TrustLedgerEntryResource: accounting_journal_entries deliberately
 * does not use BelongsToTenant (mirrors trust_ledger_entries).
 */
class AccountingJournalEntryResource extends Resource
{
    protected static ?string $model = AccountingJournalEntry::class;

    protected static ?string $slug = 'accounting-journal-entries';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Operating Ledger';

    protected static string|\UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'id';

    public static function canAccess(): bool
    {
        return parent::canAccess() && static::isFirmAccountingEligible();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && static::isFirmAccountingEligible();
    }

    private static function isFirmAccountingEligible(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        return app(AccountingEntitlementPolicyService::class)->isExpensesEnabledForFirm($firmUser->firm)
            && app(AccountingEntitlementPolicyService::class)->canApprove($firmUser->role);
    }

    public static function getEloquentQuery(): Builder
    {
        $firmUser = Auth::user()?->activeFirmUser();
        $firmId = $firmUser?->firm_id ?? 0;

        return parent::getEloquentQuery()->where('accounting_journal_entries.firm_id', $firmId);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('entry_date')->label('Date')->date()->sortable(),
                TextColumn::make('description')->label('Description')->limit(60),
                TextColumn::make('source_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state),
                TextColumn::make('postings_sum')
                    ->label('Amount')
                    ->state(fn (AccountingJournalEntry $record): string => '$'.number_format(($record->postings->sum('debit_cents')) / 100, 2)),
                TextColumn::make('reverses_journal_entry_id')->label('Reverses Entry')->placeholder('—'),
            ])
            ->defaultSort('entry_date', 'desc')
            ->filters([
                SelectFilter::make('source_type')
                    ->options(fn (): array => collect(AccountingJournalSourceType::cases())->mapWithKeys(fn ($case) => [$case->value => (string) str($case->value)->headline()])->all()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccountingJournalEntries::route('/'),
            'view' => ViewAccountingJournalEntry::route('/{record}'),
        ];
    }
}
