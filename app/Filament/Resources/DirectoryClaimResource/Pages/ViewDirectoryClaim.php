<?php

declare(strict_types=1);

namespace App\Filament\Resources\DirectoryClaimResource\Pages;

use App\Filament\Actions\Platform\ApproveDirectoryClaimAction;
use App\Filament\Actions\Platform\RejectDirectoryClaimAction;
use App\Filament\Actions\Platform\RevokeDirectoryClaimAction;
use App\Filament\Resources\DirectoryClaimResource;
use App\Marketplace\Enums\ClaimState;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ViewDirectoryClaim extends ViewRecord
{
    protected static string $resource = DirectoryClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ApproveDirectoryClaimAction::make(),
            RejectDirectoryClaimAction::make(),
            RevokeDirectoryClaimAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Claim')
                ->columns(2)
                ->schema([
                    TextEntry::make('directoryFirm.display_name')->label('Listing'),
                    TextEntry::make('firm.legal_name')->label('Claimant Firm'),
                    TextEntry::make('claimant.user.name')->label('Submitted By'),
                    TextEntry::make('state')->badge()->formatStateUsing(fn (ClaimState $state) => Str::headline($state->value)),
                    TextEntry::make('claim_basis')->label('Claim Basis')->columnSpanFull(),
                    TextEntry::make('submitted_at')->dateTime(),
                    TextEntry::make('decided_at')->dateTime(),
                    TextEntry::make('rejection_reason')->label('Rejection Reason'),
                    TextEntry::make('revocation_reason')->label('Revocation Reason'),
                    TextEntry::make('conflictsWith.id')->label('Conflicts With Claim #'),
                ]),
        ]);
    }
}
