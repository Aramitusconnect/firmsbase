<?php

declare(strict_types=1);

namespace App\Filament\Resources\DirectoryAttorneyResource\Pages;

use App\Filament\Actions\Platform\ArchiveDirectoryAttorneyAction;
use App\Filament\Actions\Platform\AssociateDirectoryAttorneyWithFirmAction;
use App\Filament\Actions\Platform\PublishDirectoryAttorneyAction;
use App\Filament\Actions\Platform\RevokeDirectoryAttorneyVerificationAction;
use App\Filament\Actions\Platform\UnpublishDirectoryAttorneyAction;
use App\Filament\Actions\Platform\VerifyDirectoryAttorneyAction;
use App\Filament\Resources\DirectoryAttorneyResource;
use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryVerification;
use App\Services\CanonicalUrlService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

class ViewDirectoryAttorney extends ViewRecord
{
    protected static string $resource = DirectoryAttorneyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewPublicProfile')
                ->label('View Public Profile')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->url(fn (DirectoryAttorney $record): string => app(CanonicalUrlService::class)->myAttorneyAttorneyUrl($record->slug))
                ->openUrlInNewTab()
                ->visible(fn (DirectoryAttorney $record): bool => $record->isPubliclyVisible()),
            EditAction::make(),
            PublishDirectoryAttorneyAction::make(),
            UnpublishDirectoryAttorneyAction::make(),
            VerifyDirectoryAttorneyAction::make(),
            RevokeDirectoryAttorneyVerificationAction::make(),
            AssociateDirectoryAttorneyWithFirmAction::make(),
            ArchiveDirectoryAttorneyAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Attorney')
                ->columns(2)
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('title')->placeholder('—'),
                    TextEntry::make('bar_number')->label('Bar Number')->placeholder('—'),
                    TextEntry::make('license_jurisdictions')
                        ->label('License Jurisdictions')
                        ->state(fn (DirectoryAttorney $record) => $record->license_jurisdictions !== [] ? implode(', ', $record->license_jurisdictions) : '—'),
                    TextEntry::make('publication_state')
                        ->badge()
                        ->formatStateUsing(fn (DirectoryPublicationState $state): string => Str::headline($state->value)),
                    TextEntry::make('source_type')->label('Source')->formatStateUsing(fn ($state) => Str::headline($state->value)),
                    TextEntry::make('last_verified_at')->label('Last verified')->dateTime(),
                    TextEntry::make('biography')->columnSpanFull()->placeholder('—'),
                ]),
            Section::make('Firm History')
                ->schema([
                    RepeatableEntry::make('firmRelationships')
                        ->hiddenLabel()
                        ->state(fn (DirectoryAttorney $record): array => $record->firmRelationships()->with('firm')->orderByDesc('started_at')->get()
                            ->map(fn ($r) => [
                                'firm' => $r->firm?->display_name ?? '—',
                                'state' => Str::headline($r->relationship_state->value),
                                'title' => $r->title ?? '—',
                                'started_at' => $r->started_at?->toDateString(),
                            ])->all())
                        ->schema([
                            TextEntry::make('firm'),
                            TextEntry::make('state')->badge(),
                            TextEntry::make('title'),
                            TextEntry::make('started_at')->label('Started'),
                        ])
                        ->columns(4),
                ])
                ->collapsed(),
            Section::make('Verifications')
                ->schema([
                    RepeatableEntry::make('verifications')
                        ->hiddenLabel()
                        ->state(function (DirectoryAttorney $record): array {
                            return DirectoryVerification::query()
                                ->where('verifiable_type', DirectoryAttorney::class)
                                ->where('verifiable_id', $record->id)
                                ->get()
                                ->map(fn ($v) => [
                                    'dimension' => Str::headline($v->dimension->value),
                                    'state' => Str::headline($v->state->value),
                                    'verified_at' => $v->verified_at?->toDateTimeString(),
                                ])->all();
                        })
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
