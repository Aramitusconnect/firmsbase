<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterResource\RelationManagers;

use App\Filament\Firm\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\MatterAccessPolicyService;
use App\Services\PaymentAccessPolicyService;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * PaymentsRelationManager — Tier1-G, "Payments" tab on ViewMatter,
 * listing this matter's Payment rows (`Matter::payments()`, a new but
 * plain, direct HasMany — Payment carries its own `matter_id` column,
 * see that model's own migration; reuses the exact same canonical
 * `payments` table PaymentResource's own table() already queries).
 *
 * Deliberately read-only with a "View" link-out to PaymentResource's
 * own ViewRecord page — Payment is a canonical financial ledger row
 * this whole mission's rule #4 forbids exposing as editable form
 * fields, same reasoning as ClientResource's own PaymentsRelationManager.
 *
 * Gate combines MatterAccessPolicyService::canAccessMatter() with
 * PaymentAccessPolicyService::canViewPayment() — the exact same role
 * ceiling PaymentResource itself is authorized by.
 */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Payments';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        if (! app(MatterAccessPolicyService::class)->canAccessMatter(Auth::user(), $ownerRecord)) {
            return false;
        }

        return app(PaymentAccessPolicyService::class)->canViewPayment($firmUser->role);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Recorded')->dateTime()->sortable(),
                TextColumn::make('client.display_name')->label('Client')->placeholder('—'),
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
