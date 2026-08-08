<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ClientResource\RelationManagers;

use App\Filament\Firm\Resources\DocumentRequestResource;
use App\Models\Client;
use App\Models\DocumentRequest;
use App\Services\DocumentRequestAccessPolicyService;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * DocumentRequestsRelationManager — "Document Requests" tab on
 * ClientResource\ViewClient, listing this client's DocumentRequest rows
 * (`Client::documentRequests()`, a real, already-defined HasMany — see
 * ContactsRelationManager's own docblock for the identical
 * "already-defined HasMany, no manual getRelationship() override
 * needed" shape).
 *
 * Deliberately read-only here, with a "View" row action linking out to
 * DocumentRequestResource's own ViewRecord page (which hosts the full
 * ItemsRelationManager with every status-transition Action) — mirrors
 * CommunicationConsentsRelationManager's "Full History" link-out
 * pattern. Creating a new request from this tab (pre-locked to this
 * client) is intentionally deferred to the "Relationship wiring on
 * Client/Matter views" follow-up item tracked separately in this
 * mission's own plan — DocumentRequestResource's own "+ New Document
 * Request" (ListDocumentRequests) is the one, fully-featured creation
 * path for this module today.
 */
class DocumentRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'documentRequests';

    protected static ?string $title = 'Document Requests';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null || ! $ownerRecord instanceof Client || (int) $firmUser->firm_id !== (int) $ownerRecord->firm_id) {
            return false;
        }

        return app(DocumentRequestAccessPolicyService::class)->canView($firmUser->role);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('matter.stage')->label('Matter')->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'fulfilled' => 'success',
                        'partially_fulfilled' => 'warning',
                        'cancelled' => 'gray',
                        default => 'info',
                    }),
                TextColumn::make('items_count')
                    ->label('Items')
                    ->state(fn (DocumentRequest $record): int => $record->items()->count()),
                TextColumn::make('due_at')->dateTime()->placeholder('—'),
                TextColumn::make('created_at')->dateTime(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([
                Action::make('viewDocumentRequest')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->url(fn ($record): string => DocumentRequestResource::getUrl('view', ['record' => $record])),
            ])
            ->toolbarActions([]);
    }
}
