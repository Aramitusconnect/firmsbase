<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterResource\RelationManagers;

use App\Filament\Firm\Resources\DocumentRequestResource;
use App\Models\DocumentRequest;
use App\Services\DocumentRequestAccessPolicyService;
use App\Services\MatterAccessPolicyService;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * DocumentRequestsRelationManager — "Document Requests" tab on
 * ViewMatter, listing this matter's DocumentRequest rows
 * (`Matter::documentRequests()`, a real, already-defined HasMany — see
 * DocumentsRelationManager's own docblock for the identical
 * "already-defined HasMany" shape on this same resource). Deliberately
 * read-only with a "View" link-out, matching ClientResource\
 * DocumentRequestsRelationManager's own reasoning — see that class's
 * docblock for why locked-client creation from this tab is deferred to
 * a separate follow-up item.
 *
 * canViewForRecord() reuses `MatterAccessPolicyService::canAccessMatter()`
 * (the same per-record boundary DocumentsRelationManager already
 * checks on this resource) IN ADDITION to this module's own
 * `DocumentRequestAccessPolicyService::canView()` role ceiling — a user
 * must clear both: assigned/blanket access to this specific matter, AND
 * a role permitted to view document requests at all.
 */
class DocumentRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'documentRequests';

    protected static ?string $title = 'Document Requests';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        if (! app(MatterAccessPolicyService::class)->canAccessMatter(Auth::user(), $ownerRecord)) {
            return false;
        }

        return app(DocumentRequestAccessPolicyService::class)->canView($firmUser->role);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('client.display_name')->label('Client')->placeholder('—'),
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
