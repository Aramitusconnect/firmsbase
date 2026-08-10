<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Enums\AutomationConditionOperator;
use App\Enums\DomainEventType;
use App\Filament\Firm\Resources\AutomationRuleResource\Pages\CreateAutomationRule;
use App\Filament\Firm\Resources\AutomationRuleResource\Pages\EditAutomationRule;
use App\Filament\Firm\Resources\AutomationRuleResource\Pages\ListAutomationRules;
use App\Models\AutomationRule;
use App\Services\Automation\AutomationActionHandlerRegistry;
use App\Services\Automation\AutomationFieldAllowlistRegistry;
use BackedEnum;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * AutomationRuleResource — Event-Driven Automation Engine, item 15.
 * Structured forms are the primary UX (Select/Repeater/KeyValue), never
 * a raw JSON editor — conditions_json/actions_json are still stored as
 * JSON, but a firm user only ever interacts with them through typed
 * fields (event/field/operator/value selects for conditions,
 * action-type select + labeled key/value pairs for each action's
 * config). CreateAutomationRule/EditAutomationRule route through
 * AutomationRuleService, never a bare Eloquent save — every save-time
 * validation (field allowlist, operator/action-type closed vocabulary,
 * requires_approval can't be forced off) applies exactly as it would
 * to a template installed via ListAutomationRules' own header action.
 *
 * ToggleColumn on `enabled` writes directly via Eloquent (Filament's
 * own built-in behavior) rather than through AutomationRuleService::setEnabled() —
 * accepted here since `enabled` carries no validation dependency on
 * conditions/actions/event_type (unlike every other field), so a bare
 * toggle write is exactly equivalent to the service call.
 */
class AutomationRuleResource extends Resource
{
    protected static ?string $model = AutomationRule::class;

    protected static ?string $slug = 'automation-rules';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?string $navigationLabel = 'Rules';

    protected static string|\UnitEnum|null $navigationGroup = 'Automation';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Rule')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(255)->columnSpanFull(),
                    Textarea::make('description')->rows(2)->columnSpanFull(),
                    Select::make('event_type')
                        ->label('Trigger Event')
                        ->options(fn (): array => collect(DomainEventType::cases())->mapWithKeys(fn ($case): array => [$case->value => str($case->value)->headline()])->all())
                        ->required()
                        ->live()
                        ->disabledOn('edit')
                        ->helperText('The trigger event cannot be changed after a rule is created — create a new rule instead.'),
                    TextInput::make('priority')
                        ->numeric()
                        ->default(0)
                        ->helperText('Higher priority rules are evaluated first.'),
                    Toggle::make('enabled')->default(true),
                    Toggle::make('requires_approval')
                        ->helperText('Automatically forced on if any action requires human approval, regardless of this toggle.'),
                ]),
            Section::make('Conditions')
                ->description('All conditions must pass for this rule to match (AND only). Leave empty to match every occurrence of the trigger event.')
                ->schema([
                    Repeater::make('conditions')
                        ->hiddenLabel()
                        ->schema([
                            Select::make('field')
                                ->options(fn (Get $get): array => collect(AutomationFieldAllowlistRegistry::allowedFields(
                                    DomainEventType::tryFrom((string) $get('../../event_type')) ?? DomainEventType::cases()[0]
                                ))->mapWithKeys(fn (string $field): array => [$field => $field])->all())
                                ->required()
                                ->searchable(),
                            Select::make('operator')
                                ->options(fn (): array => collect(AutomationConditionOperator::cases())->mapWithKeys(fn ($case): array => [$case->value => str($case->value)->headline()])->all())
                                ->required(),
                            TextInput::make('value')->required(),
                        ])
                        ->columns(3)
                        ->addActionLabel('Add condition')
                        ->defaultItems(0),
                ]),
            Section::make('Actions')
                ->description('Executed in order when this rule matches.')
                ->schema([
                    Repeater::make('actions')
                        ->hiddenLabel()
                        ->schema([
                            Select::make('action_type')
                                ->options(fn (): array => collect(app(AutomationActionHandlerRegistry::class)->registeredTypes())->mapWithKeys(fn (string $type): array => [$type => str($type)->headline()])->all())
                                ->required(),
                            KeyValue::make('config')
                                ->keyLabel('Setting')
                                ->valueLabel('Value')
                                ->reorderable(false),
                        ])
                        ->columns(2)
                        ->addActionLabel('Add action')
                        ->minItems(1)
                        ->required(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('event_type')->badge()->formatStateUsing(fn ($state): string => str((is_object($state) ? $state->value : $state))->headline()),
                IconColumn::make('is_starter_template')->label('Template')->boolean()->toggleable(isToggledHiddenByDefault: true),
                ToggleColumn::make('enabled'),
                TextColumn::make('priority')->sortable(),
                IconColumn::make('requires_approval')->boolean(),
                TextColumn::make('version')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updatedBy.user.name')->label('Last Updated By')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('event_type')
                    ->options(fn (): array => collect(DomainEventType::cases())->mapWithKeys(fn ($case): array => [$case->value => str($case->value)->headline()])->all()),
                TernaryFilter::make('enabled'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAutomationRules::route('/'),
            'create' => CreateAutomationRule::route('/create'),
            'edit' => EditAutomationRule::route('/{record}/edit'),
        ];
    }
}
