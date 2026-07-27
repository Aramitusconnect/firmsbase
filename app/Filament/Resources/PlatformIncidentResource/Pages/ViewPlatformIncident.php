<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformIncidentResource\Pages;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Filament\Actions\Platform\FlagIncidentCustomerImpactAction;
use App\Filament\Actions\Platform\FlagIncidentNotificationNeededAction;
use App\Filament\Actions\Platform\RecordIncidentRootCauseAction;
use App\Filament\Actions\Platform\ResolveIncidentAction;
use App\Filament\Actions\Platform\UpdateIncidentSeverityAction;
use App\Filament\Actions\Platform\UpdateIncidentStatusAction;
use App\Filament\Resources\PlatformIncidentResource;
use App\Models\IncidentEvent;
use App\Services\IncidentService;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * ViewPlatformIncident — current-state fields plus the full,
 * chronological timeline (every incident_events row for this
 * correlation_id, via IncidentService::timeline() — never a second,
 * divergent query). Every lifecycle action is registered here as a
 * header action, mirroring ViewPlatformSubscription's own "mutations
 * live on the View page" convention.
 */
class ViewPlatformIncident extends ViewRecord
{
    protected static string $resource = PlatformIncidentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UpdateIncidentSeverityAction::make(),
            UpdateIncidentStatusAction::make(),
            RecordIncidentRootCauseAction::make(),
            FlagIncidentCustomerImpactAction::make(),
            FlagIncidentNotificationNeededAction::make(),
            ResolveIncidentAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Current State')
                ->columns(2)
                ->schema([
                    TextEntry::make('correlation_id')->label('Incident')->fontFamily('mono'),
                    TextEntry::make('severity')
                        ->badge()
                        ->formatStateUsing(fn (IncidentSeverity $state): string => Str::headline($state->value)),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (IncidentStatus $state): string => Str::headline($state->value)),
                    TextEntry::make('message')->label('Description')->placeholder('—'),
                    IconEntry::make('customer_impact')->label('Customer impact')->boolean(),
                    IconEntry::make('notification_needed')->label('Notification needed')->boolean(),
                    TextEntry::make('root_cause')->label('Root cause')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('resolution')->label('Resolution')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('created_at')->label('Last updated')->dateTime(),
                ]),
            Section::make('Timeline')
                ->description('Every event recorded for this incident, in order.')
                ->schema([
                    UnorderedList::make(function (IncidentEvent $record): array {
                        return app(IncidentService::class)->timeline($record->correlation_id)
                            ->map(fn (IncidentEvent $event): string => sprintf(
                                '%s — %s at %s%s',
                                Str::headline($event->event_type),
                                Str::headline($event->status->value),
                                $event->created_at?->toDayDateTimeString() ?? '—',
                                $event->message ? " — {$event->message}" : '',
                            ))
                            ->all();
                    }),
                ]),
        ]);
    }
}
