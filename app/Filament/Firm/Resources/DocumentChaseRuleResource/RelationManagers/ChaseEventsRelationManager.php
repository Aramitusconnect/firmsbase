<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\DocumentChaseRuleResource\RelationManagers;

use App\Models\DocumentChaseRule;
use App\Services\DocumentRequestAccessPolicyService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * ChaseEventsRelationManager — "Chase Event Log" tab on
 * ViewDocumentChaseRule, listing this rule's append-only
 * DocumentChaseEvent rows (`DocumentChaseRule::events()`, a real,
 * already-defined HasMany).
 *
 * DELIBERATELY view-only: no header/record/toolbar actions of any kind
 * — `DocumentChaseEvent` is written EXCLUSIVELY by
 * `DocumentChaseService`'s private `logEvent()` helper (see that
 * service's own docblock), and there is no "send now" action anywhere
 * in this relation manager or this whole module: `event_type` values
 * like `reminder_queued`/`reminder_skipped` record that the chase
 * pipeline EVALUATED eligibility and logged the outcome — they do NOT
 * mean a message left this application (Firm Feature Manifest §5:
 * DocumentChaseService "computes eligibility/logs only, never actually
 * dispatches a reminder" — no scheduler exists anywhere in this
 * codebase to invoke DocumentChaseService's own eligibility-check
 * entry point in the first place). The `event_type` column is shown verbatim (Title Case only,
 * never relabeled to imply delivery, e.g. never rendered as "Reminder
 * Sent").
 */
class ChaseEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'Chase Event Log';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null || ! $ownerRecord instanceof DocumentChaseRule || (int) $firmUser->firm_id !== (int) $ownerRecord->firm_id) {
            return false;
        }

        return app(DocumentRequestAccessPolicyService::class)->canView($firmUser->role);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('event_type')
                    ->label('Event')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => (string) str((string) $state)->headline()),
                TextColumn::make('documentRequestItem.label')->label('Document')->placeholder('—'),
                TextColumn::make('actorUser.name')->label('Actor')->placeholder('System'),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
