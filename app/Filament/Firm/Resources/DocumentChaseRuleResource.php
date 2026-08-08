<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Enums\ConsentChannel;
use App\Enums\DocumentChaseRuleStatus;
use App\Filament\Firm\Resources\DocumentChaseRuleResource\Pages\CreateDocumentChaseRule;
use App\Filament\Firm\Resources\DocumentChaseRuleResource\Pages\EditDocumentChaseRule;
use App\Filament\Firm\Resources\DocumentChaseRuleResource\Pages\ListDocumentChaseRules;
use App\Filament\Firm\Resources\DocumentChaseRuleResource\Pages\ViewDocumentChaseRule;
use App\Filament\Firm\Resources\DocumentChaseRuleResource\RelationManagers\ChaseEventsRelationManager;
use App\Models\DocumentChaseRule;
use App\Models\FirmUser;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * DocumentChaseRuleResource — Firm Feature Manifest §5: "Document Chase
 * (automated reminders) — PARTIAL... DocumentChaseRule/DocumentChaseEvent.
 * Computes eligibility/logs only; no scheduler ever dispatches a
 * reminder." This resource manages the RULE configuration only
 * (cadence/escalation policy) — it never implies a reminder is actually
 * sent to any client. See ListDocumentChaseRules::getSubheading() for
 * the exact honest copy shown on every page in this resource, and
 * ChaseEventsRelationManager's own docblock for the same discipline
 * applied to the append-only event log.
 *
 * `DocumentChaseRule` has no dedicated write service (confirmed by
 * direct source read: only DocumentChaseSchedulerService::
 * applicableRule() (read-only) and DocumentChaseService (reads a rule
 * passed to it, never writes one) reference this model in production
 * code) — direct Eloquent CRUD via WrapsRecordMutationInFirmContext is
 * therefore the correct write path here, mirroring ContactResource's
 * own "no creation restriction — safe for a normal Filament resource"
 * precedent (Firm Feature Manifest §1).
 *
 * No scheduled command is wired by this module — the reminder-dispatch
 * half of Document Chase remains a known, separately-tracked gap (Firm
 * Feature Manifest §5's own "What's needed" note: "Chase still needs a
 * scheduler for the reminder half").
 *
 * Authorization: standard Laravel Policy (App\Policies\
 * DocumentChaseRulePolicy), narrower than DocumentRequestResource's own
 * ceiling — see DocumentRequestAccessPolicyService's own docblock.
 */
class DocumentChaseRuleResource extends Resource
{
    protected static ?string $model = DocumentChaseRule::class;

    protected static ?string $slug = 'document-chase-rules';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?string $navigationLabel = 'Document Chase Rules';

    protected static string|\UnitEnum|null $navigationGroup = 'Documents';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Chase Rule')
                ->description('Chase rules define reminder eligibility only. Automatic reminder sending is not yet enabled — no email, SMS, or other message is actually sent to any client when a rule matches.')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(255)->columnSpanFull(),
                    Select::make('status')
                        ->options(fn (): array => collect(DocumentChaseRuleStatus::cases())->mapWithKeys(fn ($case) => [$case->value => str($case->value)->headline()])->all())
                        ->default(DocumentChaseRuleStatus::Active->value)
                        ->required()
                        ->native(false),
                    TextInput::make('applies_to')
                        ->label('Applies To')
                        ->maxLength(255)
                        ->helperText('e.g. a practice area key. Leave blank to apply firm-wide.'),
                    Select::make('channel')
                        ->options(collect(ConsentChannel::cases())->mapWithKeys(fn (ConsentChannel $case): array => [$case->value => str($case->value)->headline()])->all())
                        ->default(ConsentChannel::Email->value)
                        ->required()
                        ->native(false),
                    TagsInput::make('reminder_offsets_days')
                        ->label('Reminder Offsets (days since requested)')
                        ->helperText('e.g. 7, 3, 1 — the days-outstanding values that make an item eligible. Calculated only; nothing dispatches a reminder yet.')
                        ->required(),
                    TextInput::make('max_reminders')
                        ->label('Max Reminders')
                        ->numeric()
                        ->minValue(0)
                        ->default(3)
                        ->required(),
                    TextInput::make('escalate_after_days')
                        ->label('Escalate After (days)')
                        ->numeric()
                        ->minValue(0)
                        ->nullable(),
                    Select::make('escalate_to_user_id')
                        ->label('Escalate To')
                        ->options(fn (): array => FirmUser::query()
                            ->with('user')
                            ->where('status', 'active')
                            ->get()
                            ->mapWithKeys(fn (FirmUser $firmUser): array => [$firmUser->user_id => $firmUser->user?->name ?? "User #{$firmUser->user_id}"])
                            ->all())
                        ->searchable()
                        ->nullable(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'active' => 'success',
                        'paused' => 'warning',
                        'archived' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('applies_to')->label('Applies To')->placeholder('Firm-wide'),
                TextColumn::make('channel')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state),
                TextColumn::make('reminder_offsets_days')
                    ->label('Offsets (days)')
                    ->formatStateUsing(fn ($state): string => is_array($state) ? implode(', ', $state) : (string) $state),
                TextColumn::make('max_reminders')->label('Max'),
                TextColumn::make('escalate_after_days')->label('Escalate After')->placeholder('—'),
                TextColumn::make('escalateToUser.name')->label('Escalate To')->placeholder('—'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(fn (): array => collect(DocumentChaseRuleStatus::cases())->mapWithKeys(fn ($case) => [$case->value => str($case->value)->headline()])->all()),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ChaseEventsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocumentChaseRules::route('/'),
            'create' => CreateDocumentChaseRule::route('/create'),
            'view' => ViewDocumentChaseRule::route('/{record}'),
            'edit' => EditDocumentChaseRule::route('/{record}/edit'),
        ];
    }
}
