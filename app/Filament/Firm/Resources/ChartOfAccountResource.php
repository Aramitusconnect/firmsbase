<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Enums\ChartOfAccountPurpose;
use App\Enums\ChartOfAccountType;
use App\Filament\Firm\Resources\ChartOfAccountResource\Actions\DeactivateChartOfAccountAction;
use App\Filament\Firm\Resources\ChartOfAccountResource\Pages\CreateChartOfAccount;
use App\Filament\Firm\Resources\ChartOfAccountResource\Pages\ListChartOfAccounts;
use App\Filament\Firm\Resources\ChartOfAccountResource\Pages\ViewChartOfAccount;
use App\Models\ChartOfAccount;
use App\Services\AccountingEntitlementPolicyService;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * ChartOfAccountResource — Trust & Accounting Integrity Hardening,
 * Mission 1.4: the first Firm-facing UI for chart_of_accounts.
 * ChartOfAccountsService::create()/deactivate() already existed and
 * were fully correct, but had zero callers outside tests — a firm with
 * the accounting/expenses entitlement enabled had no way to reach a
 * usable state (every money-changing accounting event hard-fails with
 * AccountingSetupIncompleteException until the specific purposed
 * accounts it needs exist). This Resource exposes exactly that existing
 * service, unchanged — no second accounting model, no auto-seeded
 * "default chart," consistent with this table's own documented decision
 * ("no starter/default rows are seeded... every row is created
 * explicitly through this service; firms build their own chart of
 * accounts from nothing" — ChartOfAccountsService's own docblock,
 * correction #4). The guided part of "guided setup" lives on
 * AccountingOverviewPage (a checklist of the purposes real posting code
 * actually requires), not in this Resource — this Resource is the
 * ordinary, general-purpose CRUD surface a firm uses either from that
 * checklist or independently.
 *
 * No Edit page: ChartOfAccountsService has no update() method (only
 * create()/deactivate()) — mirrors this codebase's existing financial-
 * reference-data convention (TrustAccountResource, TrustLedgerResource)
 * of "create + deactivate, never mutate an existing row's identity."
 * A firm that mis-configures an account deactivates it and creates a
 * correct one.
 */
class ChartOfAccountResource extends Resource
{
    protected static ?string $model = ChartOfAccount::class;

    protected static ?string $slug = 'chart-of-accounts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Chart of Accounts';

    protected static string|\UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'account_name';

    public static function canAccess(): bool
    {
        return parent::canAccess() && static::isFirmAccountingEligible();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && static::isFirmAccountingEligible();
    }

    public static function isFirmAccountingEligible(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        return app(AccountingEntitlementPolicyService::class)->isExpensesEnabledForFirm($firmUser->firm)
            && app(AccountingEntitlementPolicyService::class)->canApprove($firmUser->role);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Account')
                ->schema([
                    TextInput::make('account_code')
                        ->label('Account Code')
                        ->required()
                        ->maxLength(255)
                        ->helperText('A short reference code you choose, e.g. 1000.'),
                    TextInput::make('account_name')
                        ->label('Account Name')
                        ->required()
                        ->maxLength(255),
                    Select::make('account_type')
                        ->label('Account Type')
                        ->options(fn (): array => collect(ChartOfAccountType::cases())
                            ->mapWithKeys(fn ($case) => [$case->value => (string) str($case->value)->headline()])
                            ->all())
                        ->required(),
                    Select::make('purpose')
                        ->label('Purpose (optional)')
                        ->options(fn (): array => collect(ChartOfAccountPurpose::cases())
                            ->mapWithKeys(fn ($case) => [$case->value => (string) str($case->value)->headline()])
                            ->all())
                        ->native(false)
                        ->helperText('Assign a purpose only if this is the specific account posting should use for that role. At most one active account per purpose is allowed — see the Accounting Overview page for which purposes your firm still needs.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('account_code')->label('Code')->sortable()->searchable(),
                TextColumn::make('account_name')->label('Name')->sortable()->searchable(),
                TextColumn::make('account_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state),
                TextColumn::make('purpose')
                    ->label('Purpose')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state): string => $state === null ? '—' : ((string) str(is_object($state) ? $state->value : $state)->headline())),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('account_code')
            ->filters([
                SelectFilter::make('account_type')
                    ->options(fn (): array => collect(ChartOfAccountType::cases())->mapWithKeys(fn ($case) => [$case->value => (string) str($case->value)->headline()])->all()),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                DeactivateChartOfAccountAction::make(),
            ])
            ->emptyStateHeading('No chart of accounts entries yet')
            ->emptyStateDescription('Create the accounts your firm\'s accounting activity needs to post to — see the Accounting Overview page for which purposes are currently required.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChartOfAccounts::route('/'),
            'create' => CreateChartOfAccount::route('/create'),
            'view' => ViewChartOfAccount::route('/{record}'),
        ];
    }
}
