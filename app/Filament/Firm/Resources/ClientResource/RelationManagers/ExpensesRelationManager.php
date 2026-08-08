<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ClientResource\RelationManagers;

use App\Filament\Firm\Resources\ExpenseResource;
use App\Models\Client;
use App\Models\Expense;
use App\Services\AccountingEntitlementPolicyService;
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
 * ExpensesRelationManager — Tier1-G, "Expenses" tab on
 * ClientResource\ViewClient, listing every Expense across this
 * client's matters. Expense carries no `client_id` column of its own
 * (only `matter_id`), so `Client::expenses()` is a genuine
 * HasManyThrough (Client hasMany Matter, Matter hasMany Expense) — see
 * that method's own docblock on Client for why this is the same shape
 * as Matter::conflictCheckResults(), not a new aggregation.
 *
 * Deliberately read-only with a "View" link-out to ExpenseResource's
 * own ViewRecord page (which hosts the real Submit/Approve/Reject/Void
 * row actions) — never duplicating ExpenseService/ExpenseApprovalService
 * mutation here.
 *
 * Gate reuses TimeExpenseAccessPolicyService::canViewExpense() AND
 * AccountingEntitlementPolicyService::isExpensesEnabledForFirm() — the
 * exact same double gate ExpenseResource::canAccess() itself applies
 * (Expenses is an entitled module_catalog feature; a disentitled firm
 * must not see expense rows here either, even scoped to one client).
 */
class ExpensesRelationManager extends RelationManager
{
    protected static string $relationship = 'expenses';

    protected static ?string $title = 'Expenses';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null || ! $ownerRecord instanceof Client || (int) $firmUser->firm_id !== (int) $ownerRecord->firm_id) {
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
                TextColumn::make('matter.stage')->label('Matter')->placeholder('—'),
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
