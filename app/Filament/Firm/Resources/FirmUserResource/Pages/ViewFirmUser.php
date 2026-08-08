<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\FirmUserResource\Pages;

use App\Filament\Firm\Resources\FirmUserResource;
use App\Filament\Firm\Resources\FirmUserResource\Actions\ReactivateFirmUserAction;
use App\Filament\Firm\Resources\FirmUserResource\Actions\RemoveFirmUserAction;
use App\Filament\Firm\Resources\FirmUserResource\Actions\SuspendFirmUserAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * ViewFirmUser — read-only Infolist only, no `form()`/EditAction on
 * FirmUserResource at all — role/status changes are exclusively the
 * named Suspend/Reactivate/Remove Actions (same class instances reused
 * from ListFirmUsers' row actions, matching RevokeConsentAction's
 * established "one Action class, several tables" reuse pattern), never
 * a raw form field.
 */
class ViewFirmUser extends ViewRecord
{
    protected static string $resource = FirmUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ReactivateFirmUserAction::make(),
            SuspendFirmUserAction::make(),
            RemoveFirmUserAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Team Member')
                ->columns(2)
                ->schema([
                    TextEntry::make('user.name')->label('Name')->placeholder('—'),
                    TextEntry::make('user.email')->label('Email')->placeholder('—'),
                    TextEntry::make('role')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? Str::headline($state->value) : Str::headline((string) $state)),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? Str::headline($state->value) : Str::headline((string) $state))
                        ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                            'active' => 'success',
                            'invited' => 'warning',
                            'suspended', 'removed' => 'danger',
                            default => 'gray',
                        }),
                    IconEntry::make('is_primary')->label('Primary Owner')->boolean(),
                    TextEntry::make('invitedBy.name')->label('Invited By')->placeholder('—'),
                    TextEntry::make('invitation_accepted_at')->label('Joined')->dateTime()->placeholder('—'),
                    TextEntry::make('created_at')->label('Invited On')->dateTime(),
                ]),
        ]);
    }
}
