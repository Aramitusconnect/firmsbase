<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TrustLedgerResource\RelationManagers;

use App\Filament\Firm\Resources\TrustLedgerResource\Actions\ApplyTransferAction;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\ApproveTransferAction;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\DenyTransferAction;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\RequestTransferAction;
use App\Models\TrustLedger;
use App\Services\TrustAccessPolicyService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * TransferRequestsRelationManager — "Transfers" tab on ViewTrustLedger,
 * listing this ledger's TrustTransferRequest rows
 * (`TrustLedger::transferRequests()`, a real, already-defined HasMany).
 * Request/Approve/Deny/Apply are the same four Actions wired to
 * TrustTransferRequestService's own methods — see each Action's own
 * docblock.
 */
class TransferRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'transferRequests';

    protected static ?string $title = 'Transfers';

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
                TextColumn::make('matter.stage')->label('Matter')->placeholder('—'),
                TextColumn::make('invoice.id')->label('Invoice')->formatStateUsing(fn ($state): string => $state === null ? '—' : "#{$state}"),
                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'applied' => 'success',
                        'approved' => 'info',
                        'requested', 'pending_approval' => 'warning',
                        'denied', 'cancelled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('requestedBy.user.name')->label('Requested By')->placeholder('—'),
                TextColumn::make('approvedBy.user.name')->label('Approved By')->placeholder('—'),
                TextColumn::make('applied_at')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                RequestTransferAction::make(),
            ])
            ->recordActions([
                ApproveTransferAction::make(),
                DenyTransferAction::make(),
                ApplyTransferAction::make(),
            ])
            ->toolbarActions([]);
    }
}
