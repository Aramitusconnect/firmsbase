<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\InvoiceResource\RelationManagers;

use App\Filament\Firm\Resources\PaymentResource;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\BillingAccessPolicyService;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * PaymentsRelationManager — "Payments" tab on ViewInvoice, listing this
 * invoice's Payment rows (`Invoice::payments()`, a real, already-
 * defined HasMany — Payment carries its own `invoice_id` column).
 * Reuses PaymentResource's own table query shape/columns verbatim
 * (mirrors MatterResource\RelationManagers\PaymentsRelationManager's
 * identical precedent) rather than reimplementing it — deliberately
 * read-only with a "View" link-out to PaymentResource's own
 * ViewRecord page, since Payment is a canonical financial ledger row
 * this whole mission's rule #4 forbids exposing as editable form
 * fields.
 */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Payments';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null || ! $ownerRecord instanceof Invoice || (int) $firmUser->firm_id !== (int) $ownerRecord->firm_id) {
            return false;
        }

        return app(BillingAccessPolicyService::class)->canViewBilling($firmUser->role);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Recorded')->dateTime()->sortable(),
                TextColumn::make('client.display_name')->label('Client')->placeholder('—'),
                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2))
                    ->sortable(),
                TextColumn::make('payment_method')
                    ->label('Method')
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
