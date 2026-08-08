<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TrustLedgerResource\RelationManagers;

use App\Filament\Firm\Resources\TrustLedgerResource\Actions\ApproveRefundAction;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\CompleteRefundAction;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\DenyRefundAction;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\RequestRefundAction;
use App\Models\TrustLedger;
use App\Services\TrustAccessPolicyService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * RefundRequestsRelationManager — "Refunds" tab on ViewTrustLedger,
 * listing this ledger's TrustRefundRequest rows
 * (`TrustLedger::refundRequests()`, a real, already-defined HasMany).
 * Request/Approve/Deny/Complete are the same four Actions wired to
 * TrustRefundRequestService's own methods — see each Action's own
 * docblock.
 */
class RefundRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'refundRequests';

    protected static ?string $title = 'Refunds';

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
                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'completed' => 'success',
                        'approved' => 'info',
                        'requested', 'pending_approval' => 'warning',
                        'denied', 'cancelled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('requestedBy.user.name')->label('Requested By')->placeholder('—'),
                TextColumn::make('approvedBy.user.name')->label('Approved By')->placeholder('—'),
                TextColumn::make('completed_at')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                RequestRefundAction::make(),
            ])
            ->recordActions([
                ApproveRefundAction::make(),
                DenyRefundAction::make(),
                CompleteRefundAction::make(),
            ])
            ->toolbarActions([]);
    }
}
