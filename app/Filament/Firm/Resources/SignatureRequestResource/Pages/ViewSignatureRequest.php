<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\SignatureRequestResource\Pages;

use App\Filament\Firm\Resources\SignatureRequestResource;
use App\Filament\Firm\Resources\SignatureRequestResource\Actions\AttorneyReviewSignatureRequestAction;
use App\Filament\Firm\Resources\SignatureRequestResource\Actions\SendSignatureRequestAction;
use App\Filament\Firm\Resources\SignatureRequestResource\Actions\VoidSignatureRequestAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * ViewSignatureRequest — read-only Infolist only (no form() on
 * SignatureRequestResource at all). The same three workflow Actions
 * available as table row actions on ListSignatureRequests are also
 * exposed here as header actions — each Action's own visible() closure
 * already gates on the record's current status + role, so duplicating
 * them here is safe, mirroring ViewInvoice's identical established
 * pattern.
 */
class ViewSignatureRequest extends ViewRecord
{
    protected static string $resource = SignatureRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AttorneyReviewSignatureRequestAction::make(),
            SendSignatureRequestAction::make(),
            VoidSignatureRequestAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Signature Request')
                ->columns(2)
                ->schema([
                    TextEntry::make('title'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) str($state)->headline()),
                    TextEntry::make('matter.stage')->label('Matter')->placeholder('—'),
                    TextEntry::make('client.display_name')->label('Client')->placeholder('—'),
                    TextEntry::make('requestedByFirmUser.name')->label('Requested By')->placeholder('—'),
                    TextEntry::make('attorney_reviewed_at')->label('Attorney Reviewed At')->dateTime()->placeholder('Not yet reviewed'),
                    TextEntry::make('attorneyReviewedByFirmUser.name')->label('Reviewed By')->placeholder('—'),
                    TextEntry::make('attorney_review_notes')->label('Review Notes')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('sent_at')->dateTime()->placeholder('—'),
                    TextEntry::make('completed_at')->dateTime()->placeholder('—'),
                    TextEntry::make('voided_at')->dateTime()->placeholder('—'),
                    TextEntry::make('declined_at')->dateTime()->placeholder('—'),
                    TextEntry::make('expires_at')->dateTime()->placeholder('No expiration'),
                    TextEntry::make('created_at')->dateTime(),
                ]),
        ]);
    }
}
