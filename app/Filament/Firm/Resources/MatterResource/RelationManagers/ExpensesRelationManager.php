<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterResource\RelationManagers;

use App\Filament\Firm\Resources\ExpenseResource;
use App\Models\Expense;
use App\Services\AccountingEntitlementPolicyService;
use App\Services\MatterAccessPolicyService;
use App\Services\TimeExpenseAccessPolicyService;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * ExpensesRelationManager — Tier1-G, "Expenses" tab on ViewMatter,
 * listing this matter's Expense rows (`Matter::expenses()`, a new but
 * plain, direct HasMany — Expense carries its own `matter_id` column,
 * see that model's own migration).
 *
 * Deliberately read-only with a "View" link-out to ExpenseResource's
 * own ViewRecord page — never duplicating ExpenseService/
 * ExpenseApprovalService mutation here.
 *
 * Gate combines MatterAccessPolicyService::canAccessMatter() with
 * TimeExpenseAccessPolicyService::canViewExpense() AND
 * AccountingEntitlementPolicyService::isExpensesEnabledForFirm() — the
 * exact same double gate ExpenseResource::canAccess() itself applies.
 */
class ExpensesRelationManager extends RelationManager
{
    protected static string $relationship = 'expenses';

    protected static ?string $title = 'Expenses';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        if (! app(MatterAccessPolicyService::class)->canAccessMatter(Auth::user(), $ownerRecord)) {
            return false;
        }

        return app(AccountingEntitlementPolicyService::class)->isExpensesEnabledForFirm($firmUser->firm)
            && app(TimeExpenseAccessPolicyService::class)->canViewExpense($firmUser->role);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('expense_date')->label('Date')->date()->sortable(),
                TextColumn::make('vendor_name')->label('Vendor')->searchable()->sortable(),
                TextColumn::make('category.name')->label('Category')->placeholder('—'),
                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2))
                    ->sortable(),
                IconColumn::make('reimbursable')->boolean(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'approved' => 'success',
                        'submitted' => 'info',
                        'rejected' => 'danger',
                        'voided' => 'gray',
                        'draft' => 'gray',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('expense_date', 'desc')
            ->headerActions([])
            ->recordActions([
                Action::make('viewExpense')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->url(fn (Expense $record): string => ExpenseResource::getUrl('view', ['record' => $record])),
            ])
            ->toolbarActions([]);
    }
}
