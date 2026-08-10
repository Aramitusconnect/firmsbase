<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterResource\RelationManagers;

use App\Services\MatterAccessPolicyService;
use App\Services\MatterBudget\MatterBudgetAccessPolicyService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * BudgetAlertsRelationManager — Predictive Matter Budget Alerts, item
 * 17. "Alerts" tab on ViewMatter, listing this matter's
 * MatterBudgetAlert rows (`Matter::budgetAlerts()`). Read-only —
 * MatterBudgetAlertService is the only writer, and resolution/action
 * happens through the Automation Engine's own Execution Log
 * (AutomationActionExecutionResource), never here.
 *
 * Gated at the OPERATIONAL visibility tier, not profitability — every
 * alert type's own metric_snapshot_json carries percentages/hour
 * counts, never a raw internal cost-rate or compensation figure (see
 * MatterBudgetAlertService's own docblock for what each alert type
 * snapshots), so this tab is safe for the broader operational-visibility
 * role set.
 */
class BudgetAlertsRelationManager extends RelationManager
{
    protected static string $relationship = 'budgetAlerts';

    protected static ?string $title = 'Budget Alerts';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        if (! app(MatterAccessPolicyService::class)->canAccessMatter(Auth::user(), $ownerRecord)) {
            return false;
        }

        return app(MatterBudgetAccessPolicyService::class)->canViewOperationalBudget($firmUser->role);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('alert_type')->formatStateUsing(fn ($state): string => (string) str(is_object($state) ? $state->value : $state)->headline()),
                TextColumn::make('metric_key')->label('Metric')->formatStateUsing(fn ($state): string => (string) str($state)->headline()),
                TextColumn::make('severity')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => (string) str(is_object($state) ? $state->value : $state)->headline())
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'over_budget' => 'danger',
                        'high' => 'warning',
                        'warning' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('threshold_percent_crossed')->label('Threshold')->suffix('%'),
                TextColumn::make('resolved_at')->label('Resolved')->dateTime()->placeholder('Open'),
                TextColumn::make('created_at')->label('Raised')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
