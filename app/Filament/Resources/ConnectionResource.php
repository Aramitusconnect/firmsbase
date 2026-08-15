<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Actions\Platform\DisconnectConnectionAction;
use App\Filament\Resources\ConnectionResource\Pages\ListConnections;
use App\Filament\Resources\ConnectionResource\Pages\ViewConnection;
use App\Filament\Support\Integrations\IntegrationDisplay;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformConnectionDirectoryService;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * ConnectionResource — Phase 2 of the FirmsVault Platform Admin Control
 * Center mission ("Integration Operations Center"). Cross-firm,
 * List+View-only administrative oversight over `firm_integrations`,
 * mirroring App\Filament\Resources\FirmResource/FirmUserResource's
 * established Phase 1 conventions exactly: no Create/Edit pages (this
 * is oversight over connections created through the firm's own normal
 * OAuth/connect flow, never a data-entry form), and List+View only.
 *
 * UNLIKE FirmResource (whose `firms` table carries no RLS at all),
 * `firm_integrations` carries permanent FORCE ROW LEVEL SECURITY with no
 * cross-firm-read policy — the SAME structural constraint
 * FirmUserResource already solved for `firm_users`. This Resource
 * therefore follows FirmUserResource's own established shape exactly
 * (see App\Services\PlatformConnectionDirectoryService's own docblock
 * for the full architectural explanation — the same
 * PlatformFirmUserDirectoryService O(firm count) per-firm-loop pattern,
 * applied to this identically-shaped problem, per this pass's own
 * dispatch instructions):
 *  1. table() uses ->records(closure) — a raw, merged Collection built
 *     by PlatformConnectionDirectoryService::listAll() (one
 *     runWithFirmContext() call per firm) — never an Eloquent ->query(),
 *     since no single query can read across every firm's rows under
 *     firm_integrations' FORCE RLS policy.
 *  2. The View page is NOT the standard Filament ViewRecord
 *     (`{record}` route-model-binding by primary key) — a FirmIntegration
 *     row cannot be looked up by its own uuid alone without already
 *     knowing which firm's context to activate first, for the exact
 *     same reason FirmUserResource's own docblock documents for
 *     firm_users. The view route therefore carries BOTH firmUuid and
 *     connectionUuid, mirroring FirmUserResource's own
 *     `{firmUuid}/{firmUserUuid}` composite-route shape (itself modeled
 *     on App\Filament\Pages\PlatformFirmIntegrationDetailPage's
 *     established `{firmUuid}/{connectionUuid}` shape).
 *
 * "Environment" column: investigated and NOT added — FirmIntegration
 * carries no environment-shaped column at all (confirmed by reading
 * that model's own $fillable list directly: firm_id,
 * integration_provider_id, external_account_id, display_label, status,
 * scopes_granted_json, connected_by_firm_user_id, connected_at,
 * disconnected_at, last_health_check_at, last_health_status,
 * error_reason, webhook_routing_token — no "environment"/"sandbox"/
 * "production" concept anywhere on this table or its casts). Adding a
 * fabricated column here would misrepresent data that does not exist.
 *
 * Provider filter: UNLIKE PlatformIntegrationOverviewPage's own summary
 * table (one row per firm, no provider column at all), `firm_integrations`
 * genuinely carries `integration_provider_id` — a real, filterable
 * provider dimension, implemented below via
 * $onlyProviderId narrowing PlatformConnectionDirectoryService's own
 * per-firm query (mirrors the firm filter's own $onlyFirmId narrowing).
 *
 * Credential health: IntegrationCredentialService::getMaskedMetadata()
 * is the ONLY credential-derived data ever surfaced anywhere in this
 * Resource (via PlatformConnectionDirectoryService — never a decrypt
 * call, never raw ciphertext, never a token/secret/API key value). The
 * list table shows only a bounded count + nearest expiry; the View
 * page's own masked-metadata list is still fully masked (status/type/
 * expiry/non-secret display metadata only — see
 * IntegrationCredentialService::getMaskedMetadata()'s own docblock).
 */
class ConnectionResource extends Resource
{
    protected static ?string $model = FirmIntegration::class;

    protected static ?string $slug = 'connections';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static ?string $navigationLabel = 'Connections';

    /**
     * Integrations navigation group — Prompt 2 (Integration Operations)
     * regression fix. This Resource's own class docblock has always
     * described it as part of the Integration Operations Center, and
     * nine sibling Integration surfaces already declare
     * `$navigationGroup = 'Integrations'`, but this one declared none —
     * so Filament rendered "Connections" as an ungrouped, top-level
     * Admin navigation entry, visually separated from the very group it
     * belongs to. Sort 2 places it directly after Integration Overview,
     * matching the operator's own drill path (overview -> connections).
     */
    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'uuid';

    /**
     * Direct role-service check (not Laravel Policy auto-resolution) —
     * mirrors every other Checkpoint 11/Phase 2 platform-admin
     * Page/Resource's own established `canAccess()` shape
     * (PlatformIntegrationOverviewPage, PlatformFirmIntegrationsPage)
     * rather than registering a new Policy binding for FirmIntegration,
     * since firm_integrations already has no cross-firm-read policy of
     * its own to piggyback on and this Resource's whole data path
     * (PlatformConnectionDirectoryService) already enforces the same
     * gate independently on every read.
     */
    public static function canAccess(): bool
    {
        return static::canViewAny();
    }

    public static function canViewAny(): bool
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
                $firmUuid = $filters['firm_uuid']['value'] ?? null;
                $providerId = $filters['integration_provider_id']['value'] ?? null;
                $status = $filters['status']['value'] ?? null;

                // Narrow the per-firm loop to exactly one firm when a
                // firm filter is applied — the one available
                // optimization against PlatformConnectionDirectoryService's
                // otherwise O(firm count) read; see that service's own
                // docblock.
                $onlyFirmId = null;

                if (filled($firmUuid)) {
                    $onlyFirmId = Firm::query()->where('uuid', $firmUuid)->value('id');
                }

                $onlyProviderId = filled($providerId) ? (int) $providerId : null;

                $rows = app(PlatformConnectionDirectoryService::class)->listAll($admin, $onlyFirmId, $onlyProviderId);

                return $rows
                    ->when(filled($status), fn (Collection $r): Collection => $r->where('status', $status))
                    ->values();
            })
            ->filters([
                SelectFilter::make('firm_uuid')
                    ->label('Firm')
                    ->searchable()
                    ->options(fn (): array => Firm::query()->orderBy('name')->pluck('name', 'uuid')->all()),
                SelectFilter::make('integration_provider_id')
                    ->label('Provider')
                    ->options(fn (): array => IntegrationProvider::query()->orderBy('display_name')->pluck('display_name', 'id')->all()),
                SelectFilter::make('status')
                    ->options(collect(ConnectionStatus::cases())
                        ->mapWithKeys(fn (ConnectionStatus $status): array => [$status->value => Str::headline($status->value)])
                        ->all()),
            ])
            ->columns([
                TextColumn::make('firm_name')->label('Firm')->searchable()->description(fn (array $record): string => (string) ($record['firm_uuid'] ?? '')),
                TextColumn::make('provider_display_name')
                    ->label('Provider')
                    ->state(fn (array $record): string => filled($record['provider_code'] ?? null)
                        ? IntegrationDisplay::labelForProviderCode((string) $record['provider_code'])
                        : IntegrationDisplay::orAbsent($record['provider_display_name'] ?? null, 'Provider not recorded')),
                TextColumn::make('display_label')->label('Connection')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Str::headline($state))
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'disconnected' => 'gray',
                        'error', 'reauthorization_required' => 'danger',
                        default => 'warning',
                    }),
                IconColumn::make('entitlement_enabled')->label('Integration Access')->boolean(),
                // Masked identifier only — never a raw external account
                // id, never credential material.
                TextColumn::make('masked_external_account_id')
                    ->label('External Account')
                    ->placeholder('Not provided by this provider')
                    ->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('credential_active_count')
                    ->label('Credential Health')
                    ->formatStateUsing(function (array $record): string {
                        $count = (int) ($record['credential_active_count'] ?? 0);

                        if ($count === 0) {
                            return 'No active credential';
                        }

                        $expiry = $record['credential_nearest_expiry_at'] ?? null;
                        $expiryLabel = $expiry !== null ? ' — nearest expiry '.Carbon::parse($expiry)->toDayDateTimeString() : '';

                        return "{$count} active".($count === 1 ? '' : 's').$expiryLabel;
                    }),
                TextColumn::make('health_summary_state')
                    ->label('Health')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? IntegrationDisplay::NOT_CHECKED : Str::headline($state))
                    ->color(fn (?string $state): string => match ($state) {
                        'healthy' => 'success',
                        'degraded' => 'warning',
                        'action_required', 'unavailable' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('last_successful_sync_at')->label('Last Successful Sync')->dateTime()->placeholder('Never succeeded'),
                TextColumn::make('last_failure_at')->label('Last Failure')->dateTime()->placeholder('No failure recorded'),
                TextColumn::make('next_retry_at')
                    ->label('Retry State')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(function (array $record): string {
                        $nextRetryAt = $record['next_retry_at'] ?? null;
                        $consecutiveFailures = (int) ($record['consecutive_failures'] ?? 0);

                        if ($nextRetryAt === null && $consecutiveFailures === 0) {
                            return 'No failures, no retry pending';
                        }

                        $retryLabel = $nextRetryAt !== null ? Carbon::parse($nextRetryAt)->toDayDateTimeString() : 'none scheduled';

                        return "{$consecutiveFailures} consecutive failure(s), next retry: {$retryLabel}";
                    }),
                TextColumn::make('rate_limited_reset_at')->label('Rate Limit Resets At')->dateTime()->placeholder('Not rate-limited')->toggleable(isToggledHiddenByDefault: true),
                // Checkpoint 1 (FirmsVault Live Integrations,
                // checkpoint1-design-health-sandbox.md §A.3.1/§A.4)
                // additions — one TextColumn per new metrics field.
                TextColumn::make('total_request_count')->label('Total Requests')->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total_success_count')->label('Total Successes')->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_operation_label')
                    ->label('Last Operation')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? 'No operation recorded' : Str::headline($state))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_latency_ms')->label('Last Latency (ms)')->placeholder(IntegrationDisplay::NOT_MEASURED)->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_sync_lag_seconds')->label('Last Sync Lag (s)')->placeholder(IntegrationDisplay::NOT_MEASURED)->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('connected_at')->label('Connected At')->dateTime()->placeholder('Never connected')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Created')->dateTime()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label('Updated')->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->url(fn (array $record): string => ViewConnection::getUrl([
                        'firmUuid' => $record['firm_uuid'],
                        'connectionUuid' => $record['uuid'],
                    ])),
                DisconnectConnectionAction::make(),
            ])
            ->emptyStateHeading('No firm integrations yet')
            ->emptyStateDescription('Connections appear here after a firm authorizes an integration from its own panel. This console never creates a connection — provider authorization is always firm-driven.')
            // Disables Filament's default row-click action/url resolution
            // — mirrors FirmUserResource/PlatformFirmIntegrationDetailPage's
            // identical ->recordAction(null)->recordUrl(null) combination
            // for the same reason (the default closure is typed against
            // a Model, which crashes against this table's array-shaped
            // records() rows). The explicit "View" row action above is
            // the only navigation affordance.
            ->recordAction(null)
            ->recordUrl(null)
            ->defaultSort('firm_name')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConnections::route('/'),
            'view' => ViewConnection::route('/{firmUuid}/{connectionUuid}'),
        ];
    }
}
