<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Filament\Firm\Resources\ExpenseCategoryResource\Actions\DeactivateExpenseCategoryAction;
use App\Filament\Firm\Resources\ExpenseCategoryResource\Actions\ReactivateExpenseCategoryAction;
use App\Filament\Firm\Resources\ExpenseCategoryResource\Pages\CreateExpenseCategory;
use App\Filament\Firm\Resources\ExpenseCategoryResource\Pages\EditExpenseCategory;
use App\Filament\Firm\Resources\ExpenseCategoryResource\Pages\ListExpenseCategories;
use App\Models\ExpenseCategory;
use App\Services\AccountingEntitlementPolicyService;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * ExpenseCategoryResource — FirmsVault staging follow-up addition
 * ("Application Completion — Catalogs + Firm-Owned Reference Data").
 * "Firm Management → Expense Categories" per this mission's own
 * required navigation shape. ExpenseCategory is intentionally FIRM-
 * SCOPED, never global (see that model's own docblock) — every row
 * this Resource ever shows/creates belongs to the acting firm only,
 * enforced by permanent FORCE ROW LEVEL SECURITY at the database layer
 * regardless of this UI-layer scoping.
 *
 * Every mutation routes through ExpenseCategoryService — the pre-
 * existing, already-established "only writer of expense_categories" —
 * never a bare Eloquent form submission (mirrors CreateExpense/
 * EditExpense's own discipline: "route through it whenever it exists").
 */
class ExpenseCategoryResource extends Resource
{
    protected static ?string $model = ExpenseCategory::class;

    protected static ?string $slug = 'expense-categories';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $navigationLabel = 'Expense Categories';

    protected static string|\UnitEnum|null $navigationGroup = 'Firm Management';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return parent::canAccess() && static::isFirmAuthorized();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && static::isFirmAuthorized();
    }

    public static function isFirmAuthorized(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        return app(AccountingEntitlementPolicyService::class)->isExpensesEnabledForFirm($firmUser->firm)
            && app(AccountingEntitlementPolicyService::class)->canManageExpenseCategories($firmUser->role);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Expense Category')
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('chartOfAccount.name')->label('Chart of Accounts')->placeholder('—'),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->recordActions([
                DeactivateExpenseCategoryAction::make(),
                ReactivateExpenseCategoryAction::make(),
            ])
            ->emptyStateHeading('No expense categories yet');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenseCategories::route('/'),
            'create' => CreateExpenseCategory::route('/create'),
            'edit' => EditExpenseCategory::route('/{record}/edit'),
        ];
    }
}
