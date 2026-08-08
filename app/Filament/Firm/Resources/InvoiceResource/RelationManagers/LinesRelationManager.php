<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\InvoiceResource\RelationManagers;

use App\Models\Invoice;
use App\Services\BillingAccessPolicyService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * LinesRelationManager — "Lines" tab on ViewInvoice, listing this
 * invoice's InvoiceLine rows (`Invoice::lines()`, a real, already-
 * defined HasMany). Strictly read-only — there is no Edit/Delete
 * action here; the only way a line is ever added is
 * InvoiceDraftingService::addManualCharge() (AddManualChargeAction, a
 * header action on ViewInvoice / row action on ListInvoices), and
 * draftFromTimeEntries()/createFlatFee() populate the initial set.
 * Nothing here ever writes to `amount_cents`/`rate_cents` directly.
 */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Lines';

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
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('sort_order')->label('#')->sortable(),
                TextColumn::make('description'),
                TextColumn::make('line_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state),
                TextColumn::make('quantity'),
                TextColumn::make('rate_cents')
                    ->label('Rate')
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
            ])
            ->defaultSort('sort_order')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
