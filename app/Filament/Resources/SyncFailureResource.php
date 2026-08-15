<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Actions\Platform\RetrySyncFailureAction;
use App\Filament\Resources\SyncFailureResource\Pages\ListSyncFailures;
use App\Filament\Resources\SyncFailureResource\Pages\ViewSyncFailure;
use App\Filament\Support\Integrations\IntegrationDisplay;
use App\Integrations\Enums\SyncItemStatus;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Models\IntegrationSyncItem;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformIntegrationCrossFirmDirectoryService;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
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
 * SyncFailureResource — Phase 2 (FirmsVault Platform Admin Control
 * Center, "Integration Operations Center"). Global, cross-firm oversight
 * of failed `integration_sync_items` rows (status IN
 * failed_retryable/failed_permanent), backed by the new
 * PlatformIntegrationCrossFirmDirectoryService — see that class's own
 * docblock for the full per-firm-loop architectural rationale (mirrors
 * PlatformFirmUserDirectoryService/FirmUserResource's own established,
 * already-approved ->records()-closure + array-shaped-row pattern for
 * exactly the same FORCE-RLS-driven reason).
 *
 * `last_error` is never selected anywhere in the read path behind this
 * resource — "failure reason" here is the SAME governed
 * `integration_connection_health.last_failure_category` classification
 * IntegrationPlatformOversightReadService already relies on for
 * identical reasons.
 *
 * The one mutating action (Retry) reuses
 * PlatformFirmIntegrationBoundedAccessService::requeueSyncItem() — the
 * already-wired, already-audited Checkpoint 11 backend method — via the
 * new RetrySyncFailureAction (see that class's own docblock for why a
 * new, cross-firm-record-aware action class was needed instead of
 * reusing RequeueSyncItemAsSupportAction directly).
 */
class SyncFailureResource extends Resource
{
    /**
     * A real model IS set here (framework label/breadcrumb metadata
     * only — getModelLabel(), navigation title, etc.) even though the
     * table below is entirely ->records()-closure/array-row-backed, not
     * ->query()-backed, mirroring FirmUserResource's identical
     * convention. canAccess() below never calls parent::canAccess(), so
     * this never triggers a dependency on a registered Gate::policy()
     * binding for IntegrationSyncItem (see canAccess()'s own docblock).
     */
    protected static ?string $model = IntegrationSyncItem::class;

    protected static ?string $slug = 'sync-failures';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Sync Failures';

    /**
     * Operator-facing labels (§70): the underlying model is
     * IntegrationSyncItem, but an operator investigating this screen is
     * looking at sync FAILURES. Without these, Filament derives
     * "Integration Sync Item"/"Integration Sync Items" for the breadcrumb
     * and detail heading while the navigation says "Sync Failures".
     */
    protected static ?string $modelLabel = 'Sync Failure';

    protected static ?string $pluralModelLabel = 'Sync Failures';

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 20;

    /**
     * No underlying Eloquent model — this resource is entirely
     * array-record-backed (see class docblock), mirroring
     * FirmUserResource's identical "no Gate::policy() for a shared
     * tenant model" reasoning. canAccess() is overridden directly below
     * instead of relying on parent::canAccess()'s Gate-based default,
     * exactly like App\Filament\Pages\PlatformFirmIntegrationDetailPage
     * does — this deliberately avoids registering a Gate::policy()
     * binding against a tenant model (IntegrationSyncItem) that is also
     * used, unregistered, in the firm-side panel (see FirmPolicy's own
     * docblock for the exact guard-resolution hazard this sidesteps:
     * Gate::policy() is a single GLOBAL mapping per model class, not
     * scoped by auth guard).
     */
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

                $rows = app(PlatformIntegrationCrossFirmDirectoryService::class)->listSyncFailures($admin, [
                    'firm_uuid' => $filters['firm_uuid']['value'] ?? null,
                    'provider_code' => $filters['provider_code']['value'] ?? null,
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
                SelectFilter::make('provider_code')
                    ->label('Provider')
                    ->options(fn (): array => IntegrationProvider::query()->orderBy('display_name')->pluck('display_name', 'code')->all()),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        SyncItemStatus::FailedRetryable->value => 'Failed (retryable)',
                        SyncItemStatus::FailedPermanent->value => 'Failed (permanent)',
                    ]),
                Filter::make('date_range')
                    ->label('Last attempt between')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('to'),
                    ]),
            ])
            ->columns([
                TextColumn::make('firm_name')->label('Firm')->searchable(),
                TextColumn::make('provider')
                    ->label('Provider')
                    ->state(fn (array $record): string => filled($record['provider_code'] ?? null)
                        ? IntegrationDisplay::labelForProviderCode((string) $record['provider_code'])
                        : IntegrationDisplay::orAbsent($record['provider_display_name'] ?? null, 'Provider not recorded')),
                TextColumn::make('connection_label')
                    ->label('Connection')
                    ->formatStateUsing(fn (?string $state): string => IntegrationDisplay::orAbsent($state, 'Connection removed')),
                TextColumn::make('entity_type')->label('Entity Type'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? IntegrationDisplay::UNKNOWN : Str::headline($state))
                    ->color(fn (?string $state): string => $state === SyncItemStatus::FailedPermanent->value ? 'danger' : 'warning'),
                TextColumn::make('failure_category')
                    ->label('Failure Reason')
                    // The read path deliberately never selects last_error
                    // (raw provider text); this is the governed
                    // classification. An unclassified failure is named as
                    // such rather than shown as an empty dash.
                    ->formatStateUsing(fn (?string $state): string => IntegrationDisplay::orAbsent($state, 'Not classified')),
                TextColumn::make('attempt_count')->label('Attempts')->alignEnd(),
                TextColumn::make('requeue_count')->label('Retries')->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('first_seen_at')->label('First Seen')->dateTime()->placeholder(IntegrationDisplay::UNKNOWN)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_attempt_at')->label('Last Attempt')->dateTime()->sortable()->placeholder('Never attempted'),
                TextColumn::make('next_attempt_at')
                    ->label('Next Attempt')
                    ->dateTime()
                    // No next attempt is a real, important state: the item
                    // is exhausted or permanently failed and nothing will
                    // pick it up again without an operator retry.
                    ->placeholder('No retry scheduled'),
            ])
            ->recordActions([
                RetrySyncFailureAction::make(),
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->url(fn (array $record): string => ViewSyncFailure::getUrl([
                        'firmUuid' => $record['firm_uuid'],
                        'id' => $record['id'],
                    ])),
            ])
            ->emptyStateHeading('No sync failures found')
            ->defaultSort('last_attempt_at')
            // Disables Filament's default row-click resolution against
            // this array-shaped table — mirrors FirmUserResource's
            // identical ->recordAction(null)->recordUrl(null) combination
            // for the same reason (a Model-typed default closure would
            // crash against these array rows).
            ->recordAction(null)
            ->recordUrl(null)
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSyncFailures::route('/'),
            'view' => ViewSyncFailure::route('/{firmUuid}/{id}'),
        ];
    }
}
