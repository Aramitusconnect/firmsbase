<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ClientResource\RelationManagers;

use App\Filament\Firm\Resources\MatterResource;
use App\Models\Client;
use App\Models\Matter;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * MattersRelationManager — Tier1-G (Firm Feature Manifest
 * "Relationships" wiring), "Matters" tab on ClientResource\ViewClient,
 * listing this client's Matter rows (`Client::matters()`, a real,
 * already-defined HasMany — see ContactsRelationManager's own docblock
 * for the identical "already-defined HasMany, no manual
 * getRelationship() override needed" shape).
 *
 * Deliberately read-only with a "View" row action linking out to
 * MatterResource's own ViewMatter page (which hosts every real Matter
 * tab: Documents, Document Requests, Financial Evidence, Activity,
 * Conflict Checks, and this same track's own new Contacts/Tasks/
 * Deadlines/Time Entries/Expenses/Payments tabs) — mirrors
 * DocumentRequestsRelationManager's own "View" link-out pattern
 * exactly, rather than duplicating any of Matter's real tab content
 * here.
 *
 * Gate: no additional role ceiling beyond "an active firm user in this
 * client's own firm" — mirrors MatterResource::canAccess()'s own
 * documented "UX-layer, non-boundary" gate (Matter viewing itself
 * carries no role ceiling; MatterAccessPolicyService's per-record
 * assignment check is the real boundary, already enforced the moment a
 * user actually opens the "View" link above via ViewMatter::
 * resolveRecord()). This intentionally does NOT re-implement that
 * assignment-based filtering as a query predicate here (this mission's
 * own instruction: no new aggregation logic beyond simple HasMany
 * scoping) — a non-blanket-access role may see a matter row in this
 * list they cannot open, which fails safely (a 404 on click), not
 * unsafely.
 */
class MattersRelationManager extends RelationManager
{
    protected static string $relationship = 'matters';

    protected static ?string $title = 'Matters';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        return $firmUser !== null
            && $ownerRecord instanceof Client
            && (int) $firmUser->firm_id === (int) $ownerRecord->firm_id;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('matterType.name')->label('Type')->placeholder('—'),
                TextColumn::make('stage')->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? $state->value : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'open', 'active' => 'success',
                        'waiting_on_client', 'ready_for_review' => 'warning',
                        'closed', 'archived' => 'gray',
                        'conflict_check_required', 'conflict_review' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('assignedAttorney.name')->label('Attorney')->placeholder('—'),
                TextColumn::make('opened_at')->dateTime()->placeholder('—'),
                TextColumn::make('created_at')->dateTime(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([
                Action::make('viewMatter')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->url(fn (Matter $record): string => MatterResource::getUrl('view', ['record' => $record])),
            ])
            ->toolbarActions([]);
    }
}
