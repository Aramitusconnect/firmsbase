<?php

declare(strict_types=1);

namespace App\Filament\Firm\Pages;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Matter;
use App\Services\AccountingEntitlementPolicyService;
use App\Services\ExpenseReportingService;
use App\Services\TimeExpenseAccessPolicyService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * ExpenseReportPage — Firm Feature Manifest §9: "Expense reporting —
 * PARTIAL... ExpenseReportingService real, tested, zero UI." A
 * read-only Filament Page (deliberately NOT a Resource — there is no
 * underlying model to CRUD, only an aggregate read view), wired
 * directly to `ExpenseReportingService::list()`/`totalAmountCents()`,
 * reusing that service's own filter parameter shape 1:1
 * (matter_id/category_id/from/to/reimbursable/status) — the exact same
 * shape ExpenseResource's own table filters use, for consistency.
 *
 * `ExpenseReportingService`'s own documented gap: its raw `query()`
 * method returns an UNEXECUTED Builder that a caller must wrap in
 * `runWithFirmContext()` itself — but `list()`/`totalAmountCents()`
 * (the only two methods this page calls) ALREADY wrap their entire
 * body (the `query()` call PLUS the `->get()`/`->sum()` execution) in
 * their own `runWithFirmContext()` call (confirmed by direct source
 * read of that service's own docblock). This page therefore does NOT
 * add a second, redundant outer wrap around those two calls — doing so
 * would nest two independent whole-body context activations for no
 * benefit, and this codebase's own "decoy wrap" convention is about
 * avoiding exactly that kind of unnecessary nesting. This page never
 * calls `query()` directly, so the documented gap does not apply to it.
 *
 * Filters are plain `->live()` Schema fields (statePath `data`) rather
 * than Filament's built-in `Table::filters()` — deliberately, to avoid
 * hand-parsing that API's internal per-filter form-state shape for a
 * page this simple; `resolvedFilters()` is the single place raw
 * `$this->data` is turned into typed values for both the table's
 * `records()` closure and the total.
 *
 * Entitlement gating mirrors ExpenseResource::isFirmEntitled() exactly
 * — Expenses (and therefore this report) are behind the `expenses`
 * module_catalog entitlement.
 */
class ExpenseReportPage extends Page implements HasSchemas, HasTable
{
    use InteractsWithSchemas;
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Expense Report';

    protected static string|\UnitEnum|null $navigationGroup = 'Time & Expenses';

    protected static ?int $navigationSort = 30;

    protected static ?string $title = 'Expense Report';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return static::isFirmEntitled();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    private static function isFirmEntitled(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        return app(AccountingEntitlementPolicyService::class)->isExpensesEnabledForFirm($firmUser->firm)
            && app(TimeExpenseAccessPolicyService::class)->canViewExpense($firmUser->role);
    }

    public function mount(): void
    {
        $this->form->fill([
            'matter_id' => null,
            'expense_category_id' => null,
            'status' => null,
            'reimbursable' => null,
            'from' => null,
            'to' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Filters')
                    ->columns(3)
                    ->schema([
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
                            ->live()
                            ->nullable(),
                        Select::make('expense_category_id')
                            ->label('Category')
                            ->options(fn (): array => ExpenseCategory::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->live()
                            ->nullable(),
                        Select::make('status')
                            ->label('Status')
                            ->options(fn (): array => collect(ExpenseStatus::cases())->mapWithKeys(fn ($case) => [$case->value => str($case->value)->headline()])->all())
                            ->live()
                            ->nullable(),
                        Select::make('reimbursable')
                            ->label('Reimbursable')
                            ->options(['1' => 'Reimbursable', '0' => 'Non-reimbursable'])
                            ->live()
                            ->nullable(),
                        DatePicker::make('from')->label('From')->native(false)->live(),
                        DatePicker::make('to')->label('To')->native(false)->live(),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
            Section::make('Total')
                ->schema([
                    Text::make(fn (): string => '$'.number_format($this->totalAmountCents() / 100, 2))
                        ->size(TextSize::Large)
                        ->weight(FontWeight::Bold),
                ]),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->filteredEntries())
            ->columns([
                TextColumn::make('expense_date')->label('Date')->date(),
                TextColumn::make('vendor_name')->label('Vendor'),
                TextColumn::make('category.name')->label('Category')->placeholder('—'),
                TextColumn::make('matter.stage')->label('Matter')->placeholder('—'),
                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
                IconColumn::make('reimbursable')->boolean(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state),
            ])
            ->emptyStateHeading('No expenses match these filters')
            ->paginated(false);
    }

    /**
     * @return Collection<int, Expense>
     */
    private function filteredEntries(): Collection
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null || ! app(TimeExpenseAccessPolicyService::class)->canViewExpense($firmUser->role)) {
            return collect();
        }

        $filters = $this->resolvedFilters();

        return app(ExpenseReportingService::class)->list(
            $firmUser->firm,
            $filters['matterId'],
            $filters['categoryId'],
            $filters['from'],
            $filters['to'],
            $filters['reimbursable'],
            $filters['status'],
        );
    }

    private function totalAmountCents(): int
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null || ! app(TimeExpenseAccessPolicyService::class)->canViewExpense($firmUser->role)) {
            return 0;
        }

        $filters = $this->resolvedFilters();

        return app(ExpenseReportingService::class)->totalAmountCents(
            $firmUser->firm,
            $filters['matterId'],
            $filters['categoryId'],
            $filters['from'],
            $filters['to'],
            $filters['reimbursable'],
            $filters['status'],
        );
    }

    /**
     * @return array{matterId: ?int, categoryId: ?int, from: ?Carbon, to: ?Carbon, reimbursable: ?bool, status: ?ExpenseStatus}
     */
    private function resolvedFilters(): array
    {
        $data = $this->data ?? [];

        $reimbursable = $data['reimbursable'] ?? null;
        $status = $data['status'] ?? null;

        return [
            'matterId' => isset($data['matter_id']) && $data['matter_id'] !== null && $data['matter_id'] !== '' ? (int) $data['matter_id'] : null,
            'categoryId' => isset($data['expense_category_id']) && $data['expense_category_id'] !== null && $data['expense_category_id'] !== '' ? (int) $data['expense_category_id'] : null,
            'from' => isset($data['from']) && $data['from'] !== null && $data['from'] !== '' ? Carbon::parse($data['from']) : null,
            'to' => isset($data['to']) && $data['to'] !== null && $data['to'] !== '' ? Carbon::parse($data['to']) : null,
            'reimbursable' => $reimbursable !== null && $reimbursable !== '' ? (bool) ((int) $reimbursable) : null,
            'status' => $status !== null && $status !== '' ? ExpenseStatus::from($status) : null,
        ];
    }
}
