<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Enums\AutomationActionExecutionStatus;
use App\Filament\Firm\Resources\AutomationActionExecutionResource\Actions\ApproveAutomationActionAction;
use App\Filament\Firm\Resources\AutomationActionExecutionResource\Actions\RejectAutomationActionAction;
use App\Filament\Firm\Resources\AutomationActionExecutionResource\Pages\ListAutomationActionExecutions;
use App\Models\AutomationActionExecution;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * AutomationActionExecutionResource — Event-Driven Automation Engine,
 * item 9/15. The "Activity / Execution Log" — list-only (no Create/Edit
 * page at all, same discipline as PendingPaymentAllocationResource):
 * every row is created exclusively by AutomationRuleMatchingService and
 * mutated exclusively by AutomationActionDispatchJob/AutomationApprovalService.
 * Deliberately the action-execution level, not the rule-match level —
 * this is what a firm user actually cares about ("what did automation
 * DO"), with the triggering rule/event surfaced via relation columns
 * for context.
 */
class AutomationActionExecutionResource extends Resource
{
    protected static ?string $model = AutomationActionExecution::class;

    protected static ?string $slug = 'automation-activity';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Activity Log';

    protected static string|\UnitEnum|null $navigationGroup = 'Automation';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'id';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Queued')->dateTime()->sortable(),
                TextColumn::make('execution.rule.name')->label('Rule')->placeholder('—'),
                TextColumn::make('execution.domainEvent.event_type')
                    ->label('Event')
                    ->formatStateUsing(fn ($state): string => $state === null ? '—' : str((is_object($state) ? $state->value : $state))->headline()),
                TextColumn::make('action_type')->label('Action')->formatStateUsing(fn ($state): string => str((is_object($state) ? $state->value : $state))->headline()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => str((is_object($state) ? $state->value : $state))->headline())
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'succeeded' => 'success',
                        'failed' => 'danger',
                        'requires_review' => 'warning',
                        'retry_scheduled' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('attempts')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_error')->label('Note')->limit(60)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('result_reference_type')->label('Result')->formatStateUsing(fn ($state, AutomationActionExecution $record): string => $state === null ? '—' : class_basename($state)." #{$record->result_reference_id}"),
                TextColumn::make('completed_at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(fn (): array => collect(AutomationActionExecutionStatus::cases())->mapWithKeys(fn ($case): array => [$case->value => str($case->value)->headline()])->all()),
            ])
            ->recordActions([
                ApproveAutomationActionAction::make(),
                RejectAutomationActionAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAutomationActionExecutions::route('/'),
        ];
    }
}
