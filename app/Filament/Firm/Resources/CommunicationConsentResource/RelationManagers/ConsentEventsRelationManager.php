<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\CommunicationConsentResource\RelationManagers;

use App\Models\CommunicationConsent;
use App\Services\ConsentAccessPolicyService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * ConsentEventsRelationManager — "Consent History" tab on
 * ViewCommunicationConsent, listing this consent's append-only
 * `CommunicationConsentEvent` rows (`CommunicationConsent::events()`, a
 * real, already-defined HasMany — see ContactsRelationManager's own
 * docblock for the identical "already-defined HasMany, no manual
 * getRelationship() override needed" shape).
 *
 * DELIBERATELY view-only: no header/record/toolbar actions of any kind.
 * `CommunicationConsentEvent` rows are written EXCLUSIVELY, in the same
 * transaction as their paired `CommunicationConsent` write, by
 * `ConsentService::capture()`/`revoke()` — this mission's own explicit
 * rule ("never let the UI write a CommunicationConsentEvent row
 * directly, and never let the UI edit one — it's a pure audit trail").
 * There is no `CommunicationConsentEventPolicy`/create/update surface
 * anywhere in this module by design; this relation manager's complete
 * absence of any mutating action is the enforcement of that rule at the
 * UI layer, backstopped by `CommunicationConsentEvent::UPDATED_AT =
 * null` and its append-only table shape at the model/schema layer.
 */
class ConsentEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'Consent History';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null || ! $ownerRecord instanceof CommunicationConsent || (int) $firmUser->firm_id !== (int) $ownerRecord->firm_id) {
            return false;
        }

        return app(ConsentAccessPolicyService::class)->canView($firmUser->role);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('action')->badge(),
                TextColumn::make('previous_status')->label('From')->placeholder('—'),
                TextColumn::make('new_status')->label('To'),
                TextColumn::make('consent_text_version')->label('Text Version'),
                TextColumn::make('actor.name')->label('Actor')->placeholder('System'),
                TextColumn::make('source')->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
