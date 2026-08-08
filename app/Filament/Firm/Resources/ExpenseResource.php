<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Enums\ExpenseStatus;
use App\Filament\Firm\Resources\ExpenseResource\Actions\ApproveExpenseAction;
use App\Filament\Firm\Resources\ExpenseResource\Actions\RejectExpenseAction;
use App\Filament\Firm\Resources\ExpenseResource\Actions\SubmitExpenseAction;
use App\Filament\Firm\Resources\ExpenseResource\Actions\VoidExpenseAction;
use App\Filament\Firm\Resources\ExpenseResource\Pages\CreateExpense;
use App\Filament\Firm\Resources\ExpenseResource\Pages\EditExpense;
use App\Filament\Firm\Resources\ExpenseResource\Pages\ListExpenses;
use App\Filament\Firm\Resources\ExpenseResource\Pages\ViewExpense;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Matter;
use App\Services\AccountingEntitlementPolicyService;
use App\Services\TimeExpenseAccessPolicyService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * ExpenseResource — Firm Feature Manifest §6 (Tier1-C): "Expenses —
 * READY... Expense, ExpenseReportingService (read aggregation)."
 * `Expense` DOES have a dedicated write service (`ExpenseService`,
 * confirmed by direct source read — create()/editWhileDraft()/submit()/
 * void()) — every write below routes through it, NEVER a bare
 * `Expense::create()`/`$expense->update()`, matching Trust/Billing's
 * "never expose a real domain model as raw editable form fields"
 * discipline even though Expenses are not classified UNSAFE.
 *
 * `status` is NEVER an editable form field — ExpenseApprovalService is
 * the exclusive writer of Approved/Rejected (Expense's own model
 * docblock: "Only ExpenseApprovalService may move status to
 * Approved/Rejected"), and ExpenseService is the exclusive writer of
 * Submitted/Voided. Edit is additionally gated to Draft-only rows by
 * ExpensePolicy::update(), mirroring ExpenseService::editWhileDraft()'s
 * own guard exactly.
 *
 * Filters (matter/category/reimbursable/status/date-range) intentionally
 * mirror ExpenseReportingService::query()'s own parameter shape 1:1 for
 * consistency with the Expense Report page, per this mission's own
 * instruction.
 *
 * Entitlement gating: Expenses ARE behind the `expenses` module_catalog
 * entitlement (confirmed by direct source read of
 * AccountingEntitlementPolicyService — MODULE_CODE = 'expenses', used
 * by every Expense-related service (ExpenseService,
 * ExpenseApprovalService, ExpenseReportingService) method's own
 * assertExpensesEnabled() call). canAccess()/shouldRegisterNavigation()
 * below mirror FirmIntegrationResource/PlaidItemResource's exact
 * pattern: hide the nav item and resource ENTIRELY for a disentitled
 * firm (UX-layer, non-boundary) — the real boundary remains every
 * mutating service method's own assertExpensesEnabled() call, re-checked
 * unconditionally regardless of this UX-layer gate.
 */
class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static ?string $slug = 'expenses';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $navigationLabel = 'Expenses';

    protected static string|\UnitEnum|null $navigationGroup = 'Time & Expenses';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'vendor_name';

    public static function canAccess(): bool
    {
        return parent::canAccess() && static::isFirmEntitled();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && static::isFirmEntitled();
    }

    public static function isFirmEntitled(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        return app(AccountingEntitlementPolicyService::class)->isExpensesEnabledForFirm($firmUser->firm)
            && app(TimeExpenseAccessPolicyService::class)->canViewExpense($firmUser->role);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Expense')
                ->columns(2)
                ->schema([
                    TextInput::make('vendor_name')->label('Vendor')->required()->maxLength(255),
                    TextInput::make('amount')
                        ->label('Amount')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('$')
                        ->required(),
                    Select::make('expense_category_id')
                        ->label('Category')
                        ->options(fn (): array => ExpenseCategory::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->required(),
                    Select::make('matter_id')
                        ->label('Matter')
                        ->options(fn (): array => Matter::query()
                            ->with('client')
                            ->get()
                            ->mapWithKeys(fn (Matter $matter): array => [
                                $matter->id => trim(($matter->client?->display_name ?? 'Matter').' — '."#{$matter->id}"),
                            ])
                            ->all())
                        ->searchable()
                        ->nullable(),
                    DatePicker::make('expense_date')->label('Expense Date')->native(false)->default(now())->required(),
                    Toggle::make('reimbursable')->label('Reimbursable')->default(false),
                    Textarea::make('description')->rows(3)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('expense_date')->label('Date')->date()->sortable(),
                TextColumn::make('vendor_name')->label('Vendor')->searchable()->sortable(),
                TextColumn::make('category.name')->label('Category')->placeholder('—'),
                TextColumn::make('matter.stage')->label('Matter')->placeholder('—'),
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
                TextColumn::make('createdBy.user.name')->label('Created By')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('expense_date', 'desc')
            ->filters([
                SelectFilter::make('matter_id')
                    ->label('Matter')
                    ->options(fn (): array => Matter::query()
                        ->with('client')
                        ->get()
                        ->mapWithKeys(fn (Matter $matter): array => [
                            $matter->id => trim(($matter->client?->display_name ?? 'Matter').' — '."#{$matter->id}"),
                        ])
                        ->all()),
                SelectFilter::make('expense_category_id')
                    ->label('Category')
                    ->options(fn (): array => ExpenseCategory::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('status')
                    ->options(fn (): array => collect(ExpenseStatus::cases())->mapWithKeys(fn ($case) => [$case->value => str($case->value)->headline()])->all()),
                TernaryFilter::make('reimbursable'),
                Filter::make('expense_date')
                    ->schema([
                        DatePicker::make('expense_from')->label('From')->native(false),
                        DatePicker::make('expense_until')->label('Until')->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['expense_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('expense_date', '>=', $date))
                            ->when($data['expense_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('expense_date', '<=', $date));
                    }),
            ])
            ->recordActions([
                SubmitExpenseAction::make(),
                ApproveExpenseAction::make(),
                RejectExpenseAction::make(),
                VoidExpenseAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenses::route('/'),
            'create' => CreateExpense::route('/create'),
            'view' => ViewExpense::route('/{record}'),
            'edit' => EditExpense::route('/{record}/edit'),
        ];
    }
}
