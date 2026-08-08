<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\ClientResource\RelationManagers;

use App\Filament\Firm\Resources\CommunicationConsentResource;
use App\Filament\Firm\Resources\CommunicationConsentResource\Actions\CaptureClientConsentAction;
use App\Filament\Firm\Resources\CommunicationConsentResource\Actions\RevokeConsentAction;
use App\Models\Client;
use App\Models\CommunicationConsent;
use App\Services\ConsentAccessPolicyService;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * CommunicationConsentsRelationManager — "Communication Consent" tab on
 * ClientResource\ViewClient, listing this client's CommunicationConsent
 * rows (Client::communicationConsents(), a real, already-defined
 * HasMany — see ContactsRelationManager's own docblock for the
 * identical "already-defined HasMany, no manual getRelationship()
 * override needed" shape). Hosts CaptureClientConsentAction ("Record
 * Consent", client implicitly locked to this tab) as its header action
 * and RevokeConsentAction as a row action — the exact same shared
 * Action classes CommunicationConsentResource itself uses, so this tab
 * and the firm-wide list never diverge in behavior. A "Full History"
 * row action links out to CommunicationConsentResource's own ViewRecord
 * page, which hosts the full read-only ConsentEventsRelationManager
 * (append-only CommunicationConsentEvent audit trail) — not duplicated
 * here.
 */
class CommunicationConsentsRelationManager extends RelationManager
{
    protected static string $relationship = 'communicationConsents';

    protected static ?string $title = 'Communication Consent';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null || ! $ownerRecord instanceof Client || (int) $firmUser->firm_id !== (int) $ownerRecord->firm_id) {
            return false;
        }

        return app(ConsentAccessPolicyService::class)->canView($firmUser->role);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('channel')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state)
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'granted' => 'success',
                        'declined', 'revoked' => 'danger',
                        'expired' => 'warning',
                        default => 'gray',
                    }),
                IconColumn::make('currently_granted')
                    ->label('Currently Contactable')
                    ->boolean()
                    ->state(fn (CommunicationConsent $record): bool => $record->isGranted()),
                TextColumn::make('consent_text_version')->label('Text Version'),
                TextColumn::make('granted_at')->dateTime()->placeholder('—'),
                TextColumn::make('expires_at')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                CaptureClientConsentAction::make(),
            ])
            ->recordActions([
                RevokeConsentAction::make(),
                Action::make('viewHistory')
                    ->label('Full History')
                    ->icon(Heroicon::OutlinedClock)
                    ->color('gray')
                    ->url(fn (CommunicationConsent $record): string => CommunicationConsentResource::getUrl('view', ['record' => $record])),
            ])
            ->toolbarActions([]);
    }
}
