<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ClientResource\RelationManagers;

use App\Models\Client;
use App\Services\ClientCrmAccessPolicyService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * ContactsRelationManager — read-only tab on ClientResource\ViewClient
 * listing the Contacts linked to this Client (Contact::client() /
 * Client::contacts(), both real, already-defined relations — see
 * DocumentsRelationManager on MatterResource for the identical
 * "already-defined HasMany, no manual getRelationship() override
 * needed" shape). No create/edit actions here — ContactResource itself
 * is the full-CRUD surface for Contacts; this tab is purely a
 * cross-reference so a Client's own page shows who is linked to it.
 */
class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null || ! $ownerRecord instanceof Client || (int) $firmUser->firm_id !== (int) $ownerRecord->firm_id) {
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
