<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Enums\FirmUserRole;
use App\Enums\TaskWorkCategory;
use App\Filament\Firm\Resources\TaskCategoryRoleExpectationResource\Pages\CreateTaskCategoryRoleExpectation;
use App\Filament\Firm\Resources\TaskCategoryRoleExpectationResource\Pages\EditTaskCategoryRoleExpectation;
use App\Filament\Firm\Resources\TaskCategoryRoleExpectationResource\Pages\ListTaskCategoryRoleExpectations;
use App\Models\TaskCategoryRoleExpectation;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * TaskCategoryRoleExpectationResource — Leverage Ratio Optimizer, item
 * 8/26 ("Staffing Policies"). Structured, closed-vocabulary form only
 * — task_category is one of TaskWorkCategory's own cases, and
 * recommended_roles_json is a multi-select over FirmUserRole's own
 * cases — never a free-text/JSON editor. firm_id is unique per
 * task_category (StaffingPolicyService's own upsert-in-place
 * behavior), so this resource shows at most one row per category.
 */
class TaskCategoryRoleExpectationResource extends Resource
{
    protected static ?string $model = TaskCategoryRoleExpectation::class;

    protected static ?string $slug = 'staffing-policies';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Staffing Policies';

    protected static string|\UnitEnum|null $navigationGroup = 'Staffing & Leverage';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'task_category';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Staffing Policy')
                ->columns(2)
                ->schema([
                    Select::make('task_category')
                        ->label('Task Category')
                        ->options(collect(TaskWorkCategory::cases())->mapWithKeys(fn (TaskWorkCategory $c) => [$c->value => str($c->value)->headline()])->all())
                        ->required()
                        ->native(false),
                    Select::make('recommended_roles')
                        ->label('Recommended Role(s)')
                        ->options(collect(FirmUserRole::cases())->mapWithKeys(fn (FirmUserRole $r) => [$r->value => str($r->value)->headline()])->all())
                        ->multiple()
                        ->required()
                        ->native(false),
                    Textarea::make('notes')->rows(2)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('task_category')->label('Task Category')->formatStateUsing(fn ($state): string => (string) str($state)->headline())->sortable(),
                TextColumn::make('recommended_roles_json')
                    ->label('Recommended Role(s)')
                    ->formatStateUsing(fn (array $state): string => collect($state)->map(fn (string $r): string => (string) str($r)->headline())->implode(', ')),
                TextColumn::make('notes')->limit(60)->placeholder('—'),
                TextColumn::make('updatedBy.user.name')->label('Last Updated By')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->defaultSort('task_category');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTaskCategoryRoleExpectations::route('/'),
            'create' => CreateTaskCategoryRoleExpectation::route('/create'),
            'edit' => EditTaskCategoryRoleExpectation::route('/{record}/edit'),
        ];
    }
}
