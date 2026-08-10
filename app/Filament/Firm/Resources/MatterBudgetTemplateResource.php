<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Enums\FirmUserRole;
use App\Enums\MatterBudgetExpenseCategory;
use App\Filament\Firm\Resources\MatterBudgetTemplateResource\Actions\DuplicateMatterBudgetTemplateAction;
use App\Filament\Firm\Resources\MatterBudgetTemplateResource\Pages\CreateMatterBudgetTemplate;
use App\Filament\Firm\Resources\MatterBudgetTemplateResource\Pages\EditMatterBudgetTemplate;
use App\Filament\Firm\Resources\MatterBudgetTemplateResource\Pages\ListMatterBudgetTemplates;
use App\Models\MatterBudgetTemplate;
use App\Models\MatterType;
use App\Models\PracticeArea;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/**
 * MatterBudgetTemplateResource — Predictive Matter Budget Alerts, item
 * 18. Structured forms only — expected_hours_json/expected_expenses_json
 * are each a CLOSED, small vocabulary (6 FirmUserRole cases, 4
 * MatterBudgetExpenseCategory cases), so this exposes one plain,
 * labeled TextInput per case rather than a Repeater or raw JSON/
 * KeyValue editor — simpler and safer than either, since the key set
 * can never be anything other than these exact enum cases.
 *
 * ToggleColumn on `active` writes directly via Eloquent (Filament's own
 * built-in behavior), the SAME accepted shape AutomationRuleResource's
 * own `enabled` toggle uses — safe here for the identical reason: see
 * MatterBudgetTemplatePolicy, which (unlike the ToggleColumn write
 * itself) gates viewAny() for the whole resource, so an unauthorized
 * role never reaches this table at all.
 */
class MatterBudgetTemplateResource extends Resource
{
    protected static ?string $model = MatterBudgetTemplate::class;

    protected static ?string $slug = 'matter-budget-templates';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?string $navigationLabel = 'Budget Templates';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    private const HOUR_ROLES = [
        FirmUserRole::Attorney, FirmUserRole::Paralegal, FirmUserRole::LegalAssistant,
        FirmUserRole::BillingStaff, FirmUserRole::Receptionist, FirmUserRole::FirmOwner,
    ];

    private const EXPENSE_CATEGORIES = [
        MatterBudgetExpenseCategory::FilingCourtCosts, MatterBudgetExpenseCategory::VendorExpertCosts,
        MatterBudgetExpenseCategory::ReimbursableCosts, MatterBudgetExpenseCategory::OtherExpenses,
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Template')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(255)->columnSpanFull(),
                    Textarea::make('description')->rows(2)->columnSpanFull(),
                    Select::make('practice_area_id')
                        ->label('Practice Area')
                        ->options(fn (): array => PracticeArea::query()->pluck('name', 'id')->all())
                        ->searchable()
                        ->live(),
                    Select::make('matter_type_id')
                        ->label('Matter Type')
                        ->options(fn (Get $get): array => MatterType::query()
                            ->when($get('practice_area_id'), fn ($q, $practiceAreaId) => $q->where('practice_area_id', $practiceAreaId))
                            ->pluck('name', 'id')->all())
                        ->searchable(),
                    TextInput::make('expected_duration_days')->numeric()->minValue(0)->suffix('days'),
                    Toggle::make('active')->default(true),
                ]),
            Section::make('Expected Hours')
                ->description('Leave a role blank if this template does not budget hours for it.')
                ->columns(3)
                ->schema(collect(self::HOUR_ROLES)->map(fn (FirmUserRole $role) => TextInput::make("hours_{$role->value}")
                    ->label((string) str($role->value)->headline())
                    ->numeric()->minValue(0)->suffix('hrs'))->all()),
            Section::make('Expected Expenses')
                ->columns(2)
                ->schema(collect(self::EXPENSE_CATEGORIES)->map(fn (MatterBudgetExpenseCategory $category) => TextInput::make("expenses_{$category->value}")
                    ->label((string) str($category->value)->headline())
                    ->numeric()->minValue(0)->prefix('$')
                    ->helperText('Whole dollars.'))->all()),
            Section::make('Revenue & Thresholds')
                ->columns(2)
                ->schema([
                    TextInput::make('expected_revenue_cents')
                        ->label('Expected Revenue / Fee')
                        ->numeric()->minValue(0)->prefix('$')
                        ->helperText('Whole dollars. Primarily for flat-fee matters.'),
                    TextInput::make('target_gross_margin_percent')->numeric()->minValue(0)->maxValue(100)->suffix('%'),
                    TextInput::make('warning_threshold_percent')->required()->numeric()->minValue(0)->maxValue(500)->default(75)->suffix('%'),
                    TextInput::make('high_threshold_percent')->required()->numeric()->minValue(0)->maxValue(500)->default(90)->suffix('%'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('practiceArea.name')->label('Practice Area')->placeholder('—')->toggleable(),
                TextColumn::make('matterType.name')->label('Matter Type')->placeholder('—')->toggleable(),
                ToggleColumn::make('active'),
                TextColumn::make('version')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updatedBy.user.name')->label('Last Updated By')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                DuplicateMatterBudgetTemplateAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMatterBudgetTemplates::route('/'),
            'create' => CreateMatterBudgetTemplate::route('/create'),
            'edit' => EditMatterBudgetTemplate::route('/{record}/edit'),
        ];
    }
}
