<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\CommunicationConsentResource\Pages;

use App\Filament\Firm\Resources\CommunicationConsentResource;
use App\Filament\Firm\Resources\CommunicationConsentResource\Actions\RevokeConsentAction;
use App\Models\CommunicationConsent;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * ViewCommunicationConsent — read-only Infolist only (no `form()` on
 * CommunicationConsentResource at all — mirrors ViewPayment's own
 * "never expose raw CRUD on a compliance/ledger row" discipline). The
 * "Currently Contactable" entry reuses `CommunicationConsent::
 * isGranted()` — the model's own non-expiry-aware helper — rather than
 * hand-rolling an expiry check here (this mission's own explicit
 * instruction). The append-only CommunicationConsentEvent history is
 * shown below via ConsentEventsRelationManager (see
 * CommunicationConsentResource::getRelations()).
 */
class ViewCommunicationConsent extends ViewRecord
{
    protected static string $resource = CommunicationConsentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RevokeConsentAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Communication Consent')
                ->columns(2)
                ->schema([
                    TextEntry::make('client.display_name')->label('Client')->placeholder('—'),
                    TextEntry::make('channel')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state)
                        ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                            'granted' => 'success',
                            'declined', 'revoked' => 'danger',
                            'expired' => 'warning',
                            default => 'gray',
                        }),
                    IconEntry::make('currently_granted')
                        ->label('Currently Contactable')
                        ->boolean()
                        ->state(fn (CommunicationConsent $record): bool => $record->isGranted()),
                    TextEntry::make('consent_text_version')->label('Consent Text Version'),
                    TextEntry::make('granted_at')->dateTime()->placeholder('—'),
                    TextEntry::make('revoked_at')->dateTime()->placeholder('—'),
                    TextEntry::make('expires_at')->dateTime()->placeholder('—'),
                    TextEntry::make('captured_via')->label('Captured Via')->placeholder('—'),
                    TextEntry::make('captured_ip')->label('Captured IP')->placeholder('—'),
                    TextEntry::make('created_at')->label('First Recorded')->dateTime(),
                ]),
        ]);
    }
}
