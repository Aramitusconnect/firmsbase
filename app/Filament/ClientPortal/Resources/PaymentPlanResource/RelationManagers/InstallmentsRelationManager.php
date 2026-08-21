<?php

declare(strict_types=1);

namespace App\Filament\ClientPortal\Resources\PaymentPlanResource\RelationManagers;

use App\Filament\ClientPortal\Resources\PaymentPlanResource;
use App\Models\ClientPortalUser;
use App\Models\PaymentPlan;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * InstallmentsRelationManager (Client Portal) — PORTAL-003. "Installments"
 * tab on ViewPaymentPlan, listing this plan's PaymentPlanInstallment
 * rows (`PaymentPlan::installments()`, a real, already-defined
 * HasMany). Strictly read-only — no Edit/Delete/status-transition
 * action exists here, mirroring the Firm-side
 * `PaymentPlanResource\RelationManagers\InstallmentsRelationManager`.
 *
 * Column shape deliberately narrower than the Firm-side relation
 * manager: `dunning_state` is an internal collections-operations field
 * and is never shown to a client. `paid_amount_cents`/`status` are
 * CACHE columns written exclusively by PaymentApplicationService /
 * PaymentPlanInstallmentService — never by this table.
 */
class InstallmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'installments';

    protected static ?string $title = 'Installments';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        /** @var ClientPortalUser|null $portalUser */
        $portalUser = Auth::guard('client')->user();

        if ($portalUser === null || ! $ownerRecord instanceof PaymentPlan) {
            return false;
        }

        return PaymentPlanResource::isVisibleToPortalUser($ownerRecord, $portalUser);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('sequence')
            ->columns([
                TextColumn::make('sequence')->label('#')->sortable(),
                TextColumn::make('due_at')->label('Due')->dateTime()->sortable(),
                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
                TextColumn::make('paid_amount_cents')
                    ->label('Paid')
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'paid' => 'success',
                        'partially_paid', 'due' => 'warning',
                        'missed' => 'danger',
                        'waived', 'cancelled' => 'gray',
                        default => 'info',
                    }),
                TextColumn::make('paid_at')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('sequence')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
