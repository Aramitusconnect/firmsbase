<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\PaymentPlanResource\RelationManagers;

use App\Models\PaymentPlan;
use App\Services\BillingAccessPolicyService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * InstallmentsRelationManager — "Installments" tab on ViewPaymentPlan,
 * listing this plan's PaymentPlanInstallment rows (`PaymentPlan::
 * installments()`, a real, already-defined HasMany). Strictly
 * read-only — no Edit/Delete/status-transition action exists here.
 * `paid_amount_cents`/`status` are CACHE columns written exclusively
 * by PaymentApplicationService (a payment applied via PaymentResource's
 * own "Record Payment" action) and PaymentPlanInstallmentService
 * (missed/waived lifecycle, not exposed by this Tier 2 scope) — never
 * by this table.
 */
class InstallmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'installments';

    protected static ?string $title = 'Installments';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null || ! $ownerRecord instanceof PaymentPlan || (int) $firmUser->firm_id !== (int) $ownerRecord->firm_id) {
            return false;
        }

        return app(BillingAccessPolicyService::class)->canViewBilling($firmUser->role);
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
                TextColumn::make('paid_at')->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('dunning_state')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sequence')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
