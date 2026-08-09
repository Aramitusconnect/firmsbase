<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\AccountingJournalEntryResource\Pages;

use App\Filament\Firm\Resources\AccountingJournalEntryResource;
use App\Models\AccountingJournalEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * ViewAccountingJournalEntry — read-only Infolist only, no form(). The
 * posting lines are shown as a read-only RepeatableEntry (state
 * closure over the already-loaded postings relation) rather than a
 * RelationManager, mirroring ViewTrustLedgerEntry's own
 * "Chargeback History" shape — there is nothing to create/edit/delete
 * here either.
 */
class ViewAccountingJournalEntry extends ViewRecord
{
    protected static string $resource = AccountingJournalEntryResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Journal Entry')
                ->columns(2)
                ->schema([
                    TextEntry::make('entry_date')->label('Date')->date(),
                    TextEntry::make('source_type')
                        ->label('Type')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state),
                    TextEntry::make('description')->label('Description')->columnSpanFull(),
                    TextEntry::make('reverses_journal_entry_id')->label('Reverses Entry')->placeholder('—'),
                    TextEntry::make('payment.id')->label('Payment')->placeholder('—'),
                    TextEntry::make('invoice.id')->label('Invoice')->placeholder('—'),
                    TextEntry::make('expense.id')->label('Expense')->placeholder('—'),
                    TextEntry::make('trustTransferRequest.id')->label('Trust Transfer Request')->placeholder('—'),
                    TextEntry::make('created_at')->label('Posted At')->dateTime(),
                ]),
            Section::make('Postings')
                ->schema([
                    RepeatableEntry::make('postings')
                        ->hiddenLabel()
                        ->schema([
                            TextEntry::make('chartOfAccount.account_name')->label('Account')->placeholder('—'),
                            TextEntry::make('debit_cents')
                                ->label('Debit')
                                ->formatStateUsing(fn (int $state): string => $state > 0 ? '$'.number_format($state / 100, 2) : '—'),
                            TextEntry::make('credit_cents')
                                ->label('Credit')
                                ->formatStateUsing(fn (int $state): string => $state > 0 ? '$'.number_format($state / 100, 2) : '—'),
                            TextEntry::make('memo')->label('Memo')->placeholder('—'),
                        ])
                        ->columns(4),
                ]),
        ]);
    }

    protected function resolveRecord(int|string $key): AccountingJournalEntry
    {
        $record = parent::resolveRecord($key);
        $record->load(['postings.chartOfAccount']);

        return $record;
    }
}
