<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TrustLedgerResource\RelationManagers;

use App\Filament\Firm\Resources\TrustLedgerEntryResource;
use App\Models\TrustLedger;
use App\Models\TrustLedgerEntry;
use App\Services\TrustAccessPolicyService;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * EntriesRelationManager — "Entries" tab on ViewTrustLedger, listing
 * this ledger's TrustLedgerEntry rows (`TrustLedger::entries()`, a
 * real, already-defined HasMany). Strictly read-only — there is no
 * Create/Edit/Delete action here at all: every entry is posted only by
 * TrustDepositService::post()/TrustTransferRequestService::apply()/
 * TrustRefundRequestService::complete()/
 * TrustHighRiskAdjustmentService::secondApprove()/
 * TrustLedgerEntryReversalService::reverse(), each already exposed as
 * its own dedicated Action elsewhere on this page. "View" links out to
 * TrustLedgerEntryResource's own ViewRecord page (mirrors
 * PaymentsRelationManager's identical "link out to the owning
 * resource" pattern) where the Report Chargeback / Reverse / Resolve
 * Actions for a given entry live.
 */
class EntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'entries';

    protected static ?string $title = 'Entries';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null || ! $ownerRecord instanceof TrustLedger || (int) $firmUser->firm_id !== (int) $ownerRecord->firm_id) {
            return false;
        }

        return app(TrustAccessPolicyService::class)->canRequest($firmUser->role);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('posted_at')->label('Posted')->dateTime()->sortable(),
                TextColumn::make('entry_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state),
                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn (int $state): string => ($state < 0 ? '-$' : '$').number_format(abs($state) / 100, 2))
                    ->color(fn (int $state): string => $state < 0 ? 'danger' : 'success'),
                TextColumn::make('matter.stage')->label('Matter')->placeholder('—'),
                TextColumn::make('reverses_entry_id')->label('Reverses Entry')->placeholder('—'),
            ])
            ->defaultSort('posted_at', 'desc')
            ->headerActions([])
            ->recordActions([
                Action::make('viewEntry')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->url(fn (TrustLedgerEntry $record): string => TrustLedgerEntryResource::getUrl('view', ['record' => $record])),
            ])
            ->toolbarActions([]);
    }
}
