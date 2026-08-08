<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\DocumentRequestResource\RelationManagers;

use App\Filament\Firm\Resources\DocumentRequestResource\Actions\ApproveItemAction;
use App\Filament\Firm\Resources\DocumentRequestResource\Actions\MarkReceivedItemAction;
use App\Filament\Firm\Resources\DocumentRequestResource\Actions\MarkViewedItemAction;
use App\Filament\Firm\Resources\DocumentRequestResource\Actions\MoveToReviewItemAction;
use App\Filament\Firm\Resources\DocumentRequestResource\Actions\RejectItemAction;
use App\Filament\Firm\Resources\DocumentRequestResource\Actions\RequestReplacementItemAction;
use App\Filament\Firm\Resources\DocumentRequestResource\Actions\WaiveItemAction;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Services\DocumentRequestAccessPolicyService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * ItemsRelationManager — "Requested Documents" tab on ViewDocumentRequest,
 * listing this request's DocumentRequestItem rows (`DocumentRequest::
 * items()`, a real, already-defined HasMany — no manual getRelationship()
 * override needed). Every status transition is one of the 7 dedicated
 * Actions in DocumentRequestResource\Actions, each calling exactly one
 * `DocumentRequestService` method — there is no generic Edit action on
 * this table, matching Trust/Billing's "never expose a real domain
 * model as raw editable form fields" discipline (Firm Feature Manifest
 * §7).
 *
 * "Chase Attempts"/"Last Chase" are read-only, purely informational
 * columns reusing the item's own `chaseEvents()` relation — they never
 * imply a reminder was actually delivered to the client (Firm Feature
 * Manifest §5: DocumentChaseService "computes eligibility/logs only,
 * never actually dispatches a reminder"); a `reminder_queued` event
 * means the chase pipeline determined eligibility and logged that
 * fact, not that any message left this application. No "send reminder
 * now" action exists here or anywhere in this module — see
 * DocumentChaseRuleResource's own docblock for the honest-copy
 * discipline this mirrors.
 */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Requested Documents';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null || ! $ownerRecord instanceof DocumentRequest || (int) $firmUser->firm_id !== (int) $ownerRecord->firm_id) {
            return false;
        }

        return app(DocumentRequestAccessPolicyService::class)->canView($firmUser->role);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                TextColumn::make('label')->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'approved' => 'success',
                        'rejected', 'expired' => 'danger',
                        'needs_replacement' => 'warning',
                        'submitted', 'under_review' => 'info',
                        'waived' => 'gray',
                        default => 'gray',
                    }),
                IconColumn::make('is_required')->label('Required')->boolean(),
                TextColumn::make('viewed_at')->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('submitted_at')->label('Received')->dateTime()->placeholder('—'),
                TextColumn::make('reviewed_at')->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('rejected_reason')->label('Reason')->placeholder('—')->limit(40)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('waived_at')->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('chase_attempts')
                    ->label('Chase Attempts')
                    ->state(fn (DocumentRequestItem $record): int => $record->chaseEvents()->where('event_type', 'reminder_queued')->count())
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_chase_event')
                    ->label('Last Chase Event')
                    ->state(function (DocumentRequestItem $record): string {
                        $event = $record->chaseEvents()->latest('created_at')->first();

                        return $event === null ? '—' : (string) str($event->event_type)->headline().' · '.$event->created_at->diffForHumans();
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id')
            ->headerActions([])
            ->recordActions([
                MarkViewedItemAction::make(),
                MarkReceivedItemAction::make(),
                MoveToReviewItemAction::make(),
                ApproveItemAction::make(),
                RejectItemAction::make(),
                RequestReplacementItemAction::make(),
                WaiveItemAction::make(),
            ])
            ->toolbarActions([]);
    }
}
