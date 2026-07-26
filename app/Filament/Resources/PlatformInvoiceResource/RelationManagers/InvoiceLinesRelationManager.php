<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformInvoiceResource\RelationManagers;

use App\Support\MoneyDisplay;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * InvoiceLinesRelationManager — nested `platform_invoice_lines` table on
 * PlatformInvoiceResource's View page. `PlatformInvoice::lines()` is a
 * real, already-existing HasMany relation, so the standard
 * `$relationship = 'lines'` string suffices (unlike ConflictsRelationManager/
 * SyncRunsRelationManager's manual HasMany override, which those needed
 * only because `FirmIntegration` has no such relation to `conflicts`/
 * `sync_runs`).
 *
 * List-only — no Create/Edit/Delete toolbar or record actions.
 * PlatformInvoiceService::addLine() is the only place invoice lines are
 * created, and it is always called as part of invoice drafting, never
 * as a standalone admin action in this phase.
 *
 * `firm` column: a nullable per-firm usage-attribution pointer on an
 * otherwise account-level line (see PlatformInvoiceLine's own docblock —
 * "attribution, not a tenant boundary"). A null firm_id line is labeled
 * "Account-level" rather than left blank, so the distinction the
 * architecture investigation calls out is visible in the UI, not just
 * in the schema.
 */
class InvoiceLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->modifyQueryUsing(fn ($query) => $query->with('firm'))
            ->columns([
                TextColumn::make('description'),
                TextColumn::make('firm.name')->label('Firm attribution')->placeholder('Account-level'),
                TextColumn::make('usage_metric')->label('Usage metric')->placeholder('—'),
                TextColumn::make('quantity')->alignEnd(),
                TextColumn::make('unit_amount_cents')->label('Unit amount')->formatStateUsing(fn (int $state): string => MoneyDisplay::fromCents($state))->alignEnd(),
                TextColumn::make('amount_cents')->label('Amount')->formatStateUsing(fn (int $state): string => MoneyDisplay::fromCents($state))->alignEnd(),
            ])
            ->defaultSort('id')
            ->paginated([25, 50, 100])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
