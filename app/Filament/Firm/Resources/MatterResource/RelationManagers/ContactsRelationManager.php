<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterResource\RelationManagers;

use App\Services\ClientCrmAccessPolicyService;
use App\Services\MatterAccessPolicyService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * ContactsRelationManager — Tier1-G, "Contacts" tab on ViewMatter,
 * listing the Contacts linked to this matter's own Client (`Matter::
 * contacts()`, a new plain HasMany keyed on the shared `client_id`
 * column — see that method's own docblock on Matter for why this is
 * NOT a HasManyThrough: Contact has no `matter_id` column of its own,
 * and Matter `belongsTo` Client rather than `hasMany` it, so the
 * classic through-relation shape doesn't apply here).
 *
 * Deliberately read-only — ContactResource itself remains the full-CRUD
 * surface for Contacts, mirroring ClientResource\RelationManagers\
 * ContactsRelationManager's own "purely a cross-reference" reasoning.
 *
 * Gate combines MatterAccessPolicyService::canAccessMatter() (the real
 * per-record boundary every other Matter tab checks) with
 * ClientCrmAccessPolicyService::canView() (the same role ceiling
 * Contacts carries everywhere else in this codebase) — mirrors
 * DocumentRequestsRelationManager's own double-gate shape on this same
 * resource exactly.
 */
class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    protected static ?string $title = 'Contacts';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        if (! app(MatterAccessPolicyService::class)->canAccessMatter(Auth::user(), $ownerRecord)) {
            return false;
        }

        return app(ClientCrmAccessPolicyService::class)->canView($firmUser->role);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('company')->placeholder('—'),
                TextColumn::make('email')->placeholder('—'),
                TextColumn::make('phone')->placeholder('—'),
                TextColumn::make('role')->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
