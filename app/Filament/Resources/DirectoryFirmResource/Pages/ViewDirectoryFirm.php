<?php

declare(strict_types=1);

namespace App\Filament\Resources\DirectoryFirmResource\Pages;

use App\Filament\Actions\Platform\ActivateMarketplaceMembershipAction;
use App\Filament\Actions\Platform\DeactivateMarketplaceMembershipAction;
use App\Filament\Actions\Platform\PublishDirectoryFirmAction;
use App\Filament\Actions\Platform\RemoveDirectoryFirmAction;
use App\Filament\Actions\Platform\RevokeFirmVerificationAction;
use App\Filament\Actions\Platform\SuspendDirectoryFirmAction;
use App\Filament\Actions\Platform\VerifyFirmAuthorityAction;
use App\Filament\Resources\DirectoryFirmResource;
use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Services\MarketplaceBadgeService;
use App\Services\CanonicalUrlService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * ViewDirectoryFirm — Mission 2 (MyAttorney Marketplace Core),
 * checkpoint 11. Read-only infolist plus every moderation/claim/
 * verification action as a header action, mirroring ViewPlan's own
 * "mutations live on the View page" convention. Claims/correction
 * requests/verifications render via RepeatableEntry state() closures
 * reading the real relations added to DirectoryFirm this checkpoint —
 * same established pattern as ViewTrustLedger's own "Pending Approval
 * Events" section.
 */
class ViewDirectoryFirm extends ViewRecord
{
    protected static string $resource = DirectoryFirmResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewPublicProfile')
                ->label('View Public Profile')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->url(fn (DirectoryFirm $record): string => app(CanonicalUrlService::class)->myAttorneyFirmUrl($record->slug))
                ->openUrlInNewTab()
                ->visible(fn (DirectoryFirm $record): bool => $record->isPubliclyVisible()),
            EditAction::make(),
            PublishDirectoryFirmAction::make(),
            SuspendDirectoryFirmAction::make(),
            ActivateMarketplaceMembershipAction::make(),
            DeactivateMarketplaceMembershipAction::make(),
            VerifyFirmAuthorityAction::make(),
            RevokeFirmVerificationAction::make(),
            RemoveDirectoryFirmAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Listing')
                ->columns(2)
                ->schema([
                    TextEntry::make('display_name')->label('Display Name'),
                    TextEntry::make('legal_name')->label('Legal Name'),
                    TextEntry::make('slug'),
                    TextEntry::make('publication_state')
                        ->badge()
                        ->formatStateUsing(fn (DirectoryPublicationState $state): string => Str::headline($state->value)),
                    TextEntry::make('phone'),
                    TextEntry::make('website'),
                    TextEntry::make('public_email')->label('Public Email'),
                    TextEntry::make('completeness_score')->label('Completeness'),
                    TextEntry::make('source_type')->label('Source')->formatStateUsing(fn ($state) => Str::headline($state->value)),
                    TextEntry::make('description')->columnSpanFull(),
                ]),
            Section::make('Primary Office')
                ->columns(2)
                ->schema([
                    TextEntry::make('primary_office_address')
                        ->label('Address')
                        ->state(function (DirectoryFirm $record): string {
                            $office = $record->offices()->where('is_primary', true)->first();

                            if ($office === null) {
                                return '—';
                            }

                            return collect([$office->address_line1, $office->address_line2, $office->city, $office->state, $office->postal_code, $office->country])
                                ->filter()
                                ->implode(', ');
                        }),
                    TextEntry::make('primary_office_phone')
                        ->label('Office Phone')
                        ->state(fn (DirectoryFirm $record) => $record->offices()->where('is_primary', true)->value('phone') ?? '—'),
                ]),
            Section::make('Status')
                ->columns(2)
                ->schema([
                    IconEntry::make('is_claimed')->label('Claimed')->boolean(),
                    IconEntry::make('is_marketplace_member')->label('Member')->boolean(),
                    TextEntry::make('claimed_at')->dateTime(),
                    TextEntry::make('membership_activated_at')->label('Membership activated')->dateTime(),
                    TextEntry::make('last_verified_at')->label('Last verified')->dateTime(),
                    TextEntry::make('last_confirmed_by_firm_at')->label('Last confirmed by firm')->dateTime(),
                    TextEntry::make('badges')
                        ->label('Badges')
                        ->state(fn (DirectoryFirm $record): string => implode(', ', array_map(fn ($b) => $b->label(), app(MarketplaceBadgeService::class)->badgesFor($record))) ?: '—'),
                ]),
            Section::make('Claim History')
                ->schema([
                    RepeatableEntry::make('claims')
                        ->hiddenLabel()
                        ->state(fn (DirectoryFirm $record): array => $record->claims()->orderByDesc('submitted_at')->get()
                            ->map(fn ($c) => ['state' => Str::headline($c->state->value), 'submitted_at' => $c->submitted_at?->toDateTimeString(), 'decided_at' => $c->decided_at?->toDateTimeString()])
                            ->all())
                        ->schema([
                            TextEntry::make('state')->badge(),
                            TextEntry::make('submitted_at')->label('Submitted'),
                            TextEntry::make('decided_at')->label('Decided'),
                        ])
                        ->columns(3),
                ])
                ->collapsed(),
            Section::make('Correction Requests')
                ->schema([
                    RepeatableEntry::make('correctionRequests')
                        ->hiddenLabel()
                        ->state(fn (DirectoryFirm $record): array => $record->correctionRequests()->orderByDesc('created_at')->get()
                            ->map(fn ($c) => ['type' => Str::headline($c->correction_type->value), 'state' => Str::headline($c->state->value), 'created_at' => $c->created_at?->toDateTimeString()])
                            ->all())
                        ->schema([
                            TextEntry::make('type'),
                            TextEntry::make('state')->badge(),
                            TextEntry::make('created_at')->label('Reported'),
                        ])
                        ->columns(3),
                ])
                ->collapsed(),
            Section::make('Verifications')
                ->schema([
                    RepeatableEntry::make('verifications')
                        ->hiddenLabel()
                        ->state(fn (DirectoryFirm $record): array => $record->verifications()->get()
                            ->map(fn ($v) => ['dimension' => Str::headline($v->dimension->value), 'state' => Str::headline($v->state->value), 'verified_at' => $v->verified_at?->toDateTimeString()])
                            ->all())
                        ->schema([
                            TextEntry::make('dimension'),
                            TextEntry::make('state')->badge(),
                            TextEntry::make('verified_at')->label('Verified'),
                        ])
                        ->columns(3),
                ])
                ->collapsed(),
        ]);
    }
}
