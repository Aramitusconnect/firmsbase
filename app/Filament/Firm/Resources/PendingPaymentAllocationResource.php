<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Filament\Firm\Resources\PendingPaymentAllocationResource\Actions\ResolvePaymentAllocationAction;
use App\Filament\Firm\Resources\PendingPaymentAllocationResource\Pages\ListPendingPaymentAllocations;
use App\Models\PendingPaymentAllocation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * PendingPaymentAllocationResource — Mixed-Invoice Revenue Allocation
 * pass, item 3/8. List only — no Create/Edit page at all; a
 * PendingPaymentAllocation is created exclusively by
 * ManualPaymentService::submit() (never by a firm user directly) and
 * resolved exclusively via ResolvePaymentAllocationAction ->
 * PaymentAllocationResolutionService. Entitlement gating: deliberately
 * NONE — mirrors PaymentResource's own documented reasoning (no
 * payments/billing module_catalog entitlement exists anywhere);
 * authorization is role-only via PaymentAccessPolicyService::
 * canResolvePaymentAllocation().
 *
 * Pending-Cash Accounting pass, item 8 — the table's own "Held as
 * Unapplied" column deliberately never reuses "Fee"/"Cost" wording
 * while a row is still Pending: this money is genuinely unapplied
 * cash sitting in UnappliedOperatingFundsLiability, not revenue, until
 * an authorized user resolves it. The Fee/Cost columns only ever
 * populate from resolved_fee_cents/resolved_cost_cents — both remain
 * null (rendered "—") until that resolution actually happens.
 */
class PendingPaymentAllocationResource extends Resource
{
    protected static ?string $model = PendingPaymentAllocation::class;

    protected static ?string $slug = 'pending-payment-allocations';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $navigationLabel = 'Payment Allocations';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 15;

    protected static ?string $recordTitleAttribute = 'id';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Received')->dateTime()->sortable(),
                TextColumn::make('payment.client.display_name')->label('Client')->placeholder('—'),
                TextColumn::make('invoice.id')->label('Invoice')->formatStateUsing(fn ($state): string => $state === null ? '—' : "#{$state}"),
                TextColumn::make('paymentPlanInstallment.id')->label('Installment')->formatStateUsing(fn ($state): string => $state === null ? '—' : "#{$state}")->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('payment.amount_cents')
                    ->label('Payment Amount')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : '$'.number_format($state / 100, 2)),
                TextColumn::make('amount_cents')
                    ->label('Held as Unapplied')
                    ->formatStateUsing(fn (int $state, PendingPaymentAllocation $record): string => $record->isPending()
                        ? '$'.number_format($state / 100, 2)
                        : '$0.00 (cleared)')
                    ->sortable(),
                TextColumn::make('reason')->label('Reason')->limit(60)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'resolved' => 'success',
                        'cancelled' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('resolved_fee_cents')
                    ->label('Fee')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : '$'.number_format($state / 100, 2)),
                TextColumn::make('resolved_cost_cents')
                    ->label('Cost')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : '$'.number_format($state / 100, 2)),
                TextColumn::make('resolvedBy.user.name')->label('Resolved By')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cancelled_at')->label('Cancelled')->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(['pending' => 'Pending', 'resolved' => 'Resolved', 'cancelled' => 'Cancelled']),
            ])
            ->recordActions([
                ResolvePaymentAllocationAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPendingPaymentAllocations::route('/'),
        ];
    }
}
