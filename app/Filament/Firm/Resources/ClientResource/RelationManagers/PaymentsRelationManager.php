<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ClientResource\RelationManagers;

use App\Filament\Firm\Resources\PaymentResource;
use App\Models\Client;
use App\Models\Payment;
use App\Services\PaymentAccessPolicyService;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * PaymentsRelationManager — Tier1-G, "Payments" tab on
 * ClientResource\ViewClient, listing this client's Payment rows
 * (`Client::payments()`, a new but plain, direct HasMany — Payment
 * carries its own required `client_id` column, see that model's own
 * migration; this reuses the exact same canonical `payments` table
 * PaymentResource's own table() already queries, not a duplicate
 * implementation).
 *
 * Deliberately read-only (Payment/ManualPaymentRecord are canonical
 * financial ledger rows this whole mission's rule #4 forbids exposing
 * as editable form fields) with a "View" link-out to PaymentResource's
 * own ViewRecord page — mirrors DocumentRequestsRelationManager's
 * pattern. "Record Payment" already exists as a record action directly
 * on ClientResource's own table (RecordClientPaymentAction) — not
 * duplicated here to avoid a second, possibly-diverging entry point.
 *
 * Gate reuses PaymentAccessPolicyService::canViewPayment() — the exact
 * same role ceiling PaymentResource itself is authorized by.
 */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Payments';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null || ! $ownerRecord instanceof Client || (int) $firmUser->firm_id !== (int) $ownerRecord->firm_id) {
            return false;
        }

        return app(PaymentAccessPolicyService::class)->canViewPayment($firmUser->role);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Recorded')->dateTime()->sortable(),
                TextColumn::make('matter.stage')->label('Matter')->placeholder('—'),
                TextColumn::make('invoice.id')->label('Invoice')->formatStateUsing(fn ($state): string => $state === null ? '—' : "#{$state}"),
                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2))
                    ->sortable(),
                TextColumn::make('payment_method')
                    ->label('Method')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state),
                TextColumn::make('payment_classification')
                    ->label('Classification')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'succeeded' => 'success',
                        'initiated', 'pending', 'classified' => 'info',
                        'blocked', 'failed', 'disputed', 'reversed' => 'danger',
                        'refunded', 'partially_refunded' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([
                Action::make('viewPayment')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->url(fn (Payment $record): string => PaymentResource::getUrl('view', ['record' => $record])),
            ])
            ->toolbarActions([]);
    }
}
