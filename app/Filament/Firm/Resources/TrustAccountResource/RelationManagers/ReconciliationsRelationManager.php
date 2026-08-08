<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TrustAccountResource\RelationManagers;

use App\Filament\Firm\Resources\TrustAccountResource\Actions\StartReconciliationAction;
use App\Models\TrustAccount;
use App\Services\TrustAccessPolicyService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * ReconciliationsRelationManager — "Reconciliations" tab on
 * ViewTrustAccount, listing this account's TrustReconciliation rows
 * (`TrustAccount::reconciliations()`, a real, already-defined HasMany).
 * Strictly read-only — no row actions at all, and deliberately no
 * "resolve"/"correct" action anywhere on this table: a Discrepancy
 * result is only ever displayed here, never auto-corrected (project
 * rule, see StartReconciliationAction's own docblock).
 */
class ReconciliationsRelationManager extends RelationManager
{
    protected static string $relationship = 'reconciliations';

    protected static ?string $title = 'Reconciliations';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null || ! $ownerRecord instanceof TrustAccount || (int) $firmUser->firm_id !== (int) $ownerRecord->firm_id) {
            return false;
        }

        return app(TrustAccessPolicyService::class)->canRequest($firmUser->role);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('period_start')->label('Period Start')->date(),
                TextColumn::make('period_end')->label('Period End')->date(),
                TextColumn::make('system_balance_cents')
                    ->label('System Balance')
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
                TextColumn::make('asserted_bank_balance_cents')
                    ->label('Asserted Bank Balance')
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
                TextColumn::make('discrepancy_cents')
                    ->label('Discrepancy')
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2))
                    ->color(fn (int $state): string => $state === 0 ? 'success' : 'danger'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'balanced' => 'success',
                        'discrepancy' => 'danger',
                        'in_progress' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('performedBy.user.name')->label('Performed By')->placeholder('—'),
                TextColumn::make('completed_at')->dateTime()->placeholder('—')->sortable(),
            ])
            ->defaultSort('completed_at', 'desc')
            ->headerActions([
                StartReconciliationAction::make(),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
