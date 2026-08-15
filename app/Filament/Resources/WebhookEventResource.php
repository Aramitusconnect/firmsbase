<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\WebhookEventResource\Pages\ListWebhookEvents;
use App\Filament\Resources\WebhookEventResource\Pages\ViewWebhookEvent;
use App\Filament\Support\Integrations\IntegrationDisplay;
use App\Integrations\Enums\WebhookInboundEventStatus;
use App\Integrations\Models\IntegrationInboundWebhookEvent;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformIntegrationCrossFirmDirectoryService;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * WebhookEventResource — Phase 2 (FirmsVault Platform Admin Control
 * Center, "Integration Operations Center"). Global, cross-firm oversight
 * of PERSISTED `integration_inbound_webhook_events` rows only — per the
 * architecture investigation's finding, live webhook delivery is out of
 * scope; only durable, already-verified-and-recorded events are ever
 * shown here. List+View only, NO mutating action of any kind — this
 * table is a firm-facing activity record, not an admin-operable queue.
 *
 * Redaction (load-bearing): `payload_reference_json`/`payload_hash` are
 * never selected anywhere in the read path behind this resource (see
 * PlatformIntegrationCrossFirmDirectoryService::WEBHOOK_EVENT_COLUMNS —
 * an explicit SQL column allowlist, not a UI-level omission) — even
 * though `payload_reference_json` is itself already a sanitized,
 * allowlisted reference (never a raw provider body, per the model's own
 * docblock), this resource is deliberately MORE conservative than the
 * schema requires: no payload-shaped column is ever surfaced here at
 * all. Provider signing secrets and authorization headers are never
 * columns on this table in the first place (this table is created only
 * AFTER inbound signature verification has already succeeded).
 */
class WebhookEventResource extends Resource
{
    /**
     * See SyncFailureResource's own docblock for why a real model is set
     * here (framework label metadata only) while canAccess() below is
     * still fully self-contained and never calls parent::canAccess().
     */
    protected static ?string $model = IntegrationInboundWebhookEvent::class;

    protected static ?string $slug = 'webhook-events';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?string $navigationLabel = 'Webhook Events';

    protected static ?string $modelLabel = 'Webhook Event';

    protected static ?string $pluralModelLabel = 'Webhook Events';

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 21;

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessIntegrationOversight($admin)->allowed;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->records(function (?array $filters): Collection {
                $admin = Auth::guard('platform_admin')->user();

                if (! $admin instanceof PlatformAdmin) {
                    return collect();
                }

                $filters ??= [];

                $rows = app(PlatformIntegrationCrossFirmDirectoryService::class)->listWebhookEvents($admin, [
                    'firm_uuid' => $filters['firm_uuid']['value'] ?? null,
                    'provider_key' => $filters['provider_key']['value'] ?? null,
                    'event_type' => $filters['event_type']['contains'] ?? null,
                    'status' => $filters['status']['value'] ?? null,
                    'from' => $filters['date_range']['from'] ?? null,
                    'to' => $filters['date_range']['to'] ?? null,
                ]);

                return $rows->values();
            })
            ->filters([
                SelectFilter::make('firm_uuid')
                    ->label('Firm')
                    ->searchable()
                    ->options(fn (): array => Firm::query()->orderBy('name')->pluck('name', 'uuid')->all()),
                // Provider options come from the code-defined ProviderKey
                // registry (no DB query at all) — NOT a distinct() query
                // against integration_inbound_webhook_events itself,
                // which would always return zero rows here: this panel
                // has zero standing tenant context (see
                // AdminPanelProvider's own docblock), and that table
                // carries permanent FORCE ROW LEVEL SECURITY with no
                // cross-firm-read policy.
                // Canonical provider labels. Str::headline() on the raw
                // key rendered "Googleworkspace"/"Microsoft365" — a
                // string no operator recognises and no support ticket
                // ever says. IntegrationDisplay resolves the seeded
                // catalog's display_name instead (§35).
                SelectFilter::make('provider_key')
                    ->label('Provider')
                    ->options(fn (): array => IntegrationDisplay::providerFilterOptions()),
                // Free-text "contains" filter, not a Select — event_type
                // is an unbounded, free-form string with no closed
                // vocabulary (unlike status), and — same reasoning as the
                // provider filter above — there is no RLS-safe way to
                // enumerate distinct real values here for dropdown
                // options.
                Filter::make('event_type')
                    ->label('Event type')
                    ->schema([
                        TextInput::make('contains')->label('Event type contains'),
                    ]),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(WebhookInboundEventStatus::cases())
                        ->mapWithKeys(fn (WebhookInboundEventStatus $status): array => [$status->value => Str::headline($status->value)])
                        ->all()),
                Filter::make('date_range')
                    ->label('Received between')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('to'),
                    ]),
            ])
            ->columns([
                TextColumn::make('firm_name')->label('Firm')->searchable(),
                TextColumn::make('provider_key')
                    ->label('Provider')
                    ->formatStateUsing(fn (?string $state): string => IntegrationDisplay::labelForProviderCode($state)),
                TextColumn::make('event_type')
                    ->label('Event Type')
                    ->formatStateUsing(fn (?string $state): string => IntegrationDisplay::orAbsent($state, 'Not recorded')),
                TextColumn::make('connection_label')
                    ->label('Connection')
                    ->formatStateUsing(fn (?string $state): string => IntegrationDisplay::orAbsent($state, 'Connection removed')),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? IntegrationDisplay::UNKNOWN : Str::headline($state))
                    ->color(fn (?string $state): string => match ($state) {
                        'processed' => 'success',
                        'failed' => 'danger',
                        'skipped' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('processing_attempts')->label('Attempts')->alignEnd(),
                TextColumn::make('received_at')->label('Received At')->dateTime()->sortable(),
                TextColumn::make('processed_at')
                    ->label('Processed At')
                    ->dateTime()
                    // An unprocessed event has genuinely not been
                    // processed — that is a state, not a missing value.
                    ->placeholder('Not yet processed'),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->url(fn (array $record): string => ViewWebhookEvent::getUrl([
                        'firmUuid' => $record['firm_uuid'],
                        'id' => $record['id'],
                    ])),
            ])
            ->emptyStateHeading('No webhook events found')
            ->emptyStateDescription('Only persisted, already-verified inbound webhook events are shown here — never live delivery.')
            ->defaultSort('received_at')
            ->recordAction(null)
            ->recordUrl(null)
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWebhookEvents::route('/'),
            'view' => ViewWebhookEvent::route('/{firmUuid}/{id}'),
        ];
    }
}
