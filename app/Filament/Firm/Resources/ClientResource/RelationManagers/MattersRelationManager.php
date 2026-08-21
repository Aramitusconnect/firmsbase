<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ClientResource\RelationManagers;

use App\Filament\Firm\Resources\MatterResource;
use App\Models\Client;
use App\Models\ClientPortalMatterGrant;
use App\Models\Matter;
use App\Services\ClientPortalMatterAccessGrantService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
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
                TextColumn::make('portalAccess')
                    ->label('Portal Access')
                    ->badge()
                    ->state(fn (Matter $record): string => static::activeGrant($record) !== null ? 'Granted' : 'Not granted')
                    ->color(fn (Matter $record): string => static::activeGrant($record) !== null ? 'success' : 'gray'),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([
                Action::make('viewMatter')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->url(fn (Matter $record): string => MatterResource::getUrl('view', ['record' => $record])),
                // Mission 4 (Client Portal Activation), finding 4.1 —
                // client_portal_matter_grants had zero production
                // writers before this. Routes exclusively through
                // ClientPortalMatterAccessGrantService::grant(), never a
                // bare mutation. Visible only when no active grant
                // exists for this matter.
                Action::make('grantPortalAccess')
                    ->label('Grant Portal Access')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('This client will be able to see this matter (status, practice area, attorney, opened/closed dates) inside their Client Portal.')
                    ->visible(fn (Matter $record): bool => static::activeGrant($record) === null)
                    ->action(function (Matter $record): void {
                        $firmUser = Auth::user()?->activeFirmUser();

                        if ($firmUser === null) {
                            Notification::make()->title('Not permitted')->danger()->send();

                            return;
                        }

                        $client = $this->getOwnerRecord();

                        if (! $client instanceof Client) {
                            Notification::make()->title('Not permitted')->danger()->send();

                            return;
                        }

                        try {
                            app(ClientPortalMatterAccessGrantService::class)->grant(
                                $firmUser->firm,
                                $client,
                                $record,
                                $firmUser,
                            );

                            Notification::make()->title('Portal access granted')->success()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('Could not grant portal access')->body($e->getMessage())->danger()->send();
                        }
                    }),
                // Visible only when an active grant exists. Routes
                // exclusively through
                // ClientPortalMatterAccessGrantService::revoke() —
                // revoked_at is stamped, the row is never deleted.
                Action::make('revokePortalAccess')
                    ->label('Revoke Portal Access')
                    ->icon(Heroicon::OutlinedEyeSlash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('This client will no longer be able to see this matter inside their Client Portal.')
                    ->visible(fn (Matter $record): bool => static::activeGrant($record) !== null)
                    ->action(function (Matter $record): void {
                        $firmUser = Auth::user()?->activeFirmUser();

                        if ($firmUser === null) {
                            Notification::make()->title('Not permitted')->danger()->send();

                            return;
                        }

                        $grant = static::activeGrant($record);

                        if ($grant === null) {
                            Notification::make()->title('No active portal access grant found for this matter.')->danger()->send();

                            return;
                        }

                        try {
                            app(ClientPortalMatterAccessGrantService::class)->revoke($firmUser->firm, $grant, $firmUser);

                            Notification::make()->title('Portal access revoked')->success()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title('Could not revoke portal access')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->toolbarActions([]);
    }

    /**
     * Resolves the currently active (non-revoked)
     * ClientPortalMatterGrant for this matter, if any — used by both
     * the "Portal Access" status column and the grant/revoke Actions'
     * own visibility checks. Wrapped in runWithFirmContext() per this
     * mission's own instruction, matching every other query against a
     * FORCE-RLS'd table elsewhere in this codebase.
     */
    private static function activeGrant(Matter $record): ?ClientPortalMatterGrant
    {
        return (new TenantContextService)->runWithFirmContext(
            $record->firm_id,
            fn () => ClientPortalMatterGrant::query()
                ->where('matter_id', $record->id)
                ->whereNull('revoked_at')
                ->first(),
        );
    }
}
