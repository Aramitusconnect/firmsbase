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
use App\Services\StatusPagePublicationCapabilityService;
use App\Services\StatusPageService;
use Carbon\CarbonInterval;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
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
                    TextEntry::make('message')->label('Description')->placeholder('Not recorded'),
                    IconEntry::make('customer_impact')->label('Customer impact')->boolean(),
                    IconEntry::make('notification_needed')->label('Notification needed')->boolean(),
                    TextEntry::make('root_cause')->label('Root cause')->placeholder('Not recorded')->columnSpanFull(),
                    TextEntry::make('resolution')->label('Resolution')->placeholder('Not recorded')->columnSpanFull(),
                    TextEntry::make('created_at')->label('Last updated')->dateTime(),
                    // Detected/resolved/duration are read from the
                    // incident's own timeline rows, not from columns —
                    // the opened and resolved events already carry
                    // those timestamps.
                    TextEntry::make('detected_at')
                        ->label('Detected at')
                        ->state(fn (IncidentEvent $record): string => $this->facts($record)['detected_at']?->toDayDateTimeString() ?? 'Unknown'),
                    TextEntry::make('resolved_at')
                        ->label('Resolved at')
                        ->state(fn (IncidentEvent $record): string => $this->facts($record)['resolved_at']?->toDayDateTimeString() ?? 'Not resolved'),
                    TextEntry::make('duration')
                        ->label('Time to resolution')
                        ->state(function (IncidentEvent $record): string {
                            $seconds = $this->facts($record)['duration_seconds'];

                            return $seconds === null ? 'Still open' : CarbonInterval::seconds($seconds)->cascade()->forHumans();
                        }),
                ]),
            $this->ownershipSection(),
            $this->customerCommunicationSection(),
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

    /**
     * Ownership is not a field this platform has. Stating that
     * plainly is better than an empty "Owner:" row, which reads as an
     * unassigned incident rather than an unrecordable one.
     */
    private function ownershipSection(): Section
    {
        $evidence = app(IncidentService::class)->ownershipEvidence();

        return Section::make('Ownership — Not Recorded')
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->collapsible()
            ->schema([
                TextEntry::make('incident_commander')
                    ->label('Incident commander')
                    ->state('Not Recorded')
                    ->badge()
                    ->color('warning'),
                TextEntry::make('ownership_reason')
                    ->label('')
                    ->state($evidence['reason'])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * The real, recorded relationship between this incident and any
     * customer-facing status update, via
     * status_page_events.incident_correlation_id. Never inferred from
     * text matching.
     */
    private function customerCommunicationSection(): Section
    {
        return Section::make('Customer Communication')
            ->icon(Heroicon::OutlinedMegaphone)
            ->schema([
                TextEntry::make('public_communication')
                    ->label('')
                    ->state(function (IncidentEvent $record): string {
                        $updates = app(StatusPageService::class)->forIncident($record->correlation_id);
                        $capability = app(StatusPagePublicationCapabilityService::class);

                        if ($updates->isEmpty()) {
                            return 'No status update has been linked to this incident. '.$capability->disclosure();
                        }

                        return sprintf(
                            '%d status update record(s) linked to this incident. %s',
                            $updates->count(),
                            $capability->disclosure(),
                        );
                    })
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array{detected_at: ?Carbon, resolved_at: ?Carbon, duration_seconds: ?int, event_count: int}
     */
    private function facts(IncidentEvent $record): array
    {
        return app(IncidentService::class)->derivedFacts($record->correlation_id);
    }
}
