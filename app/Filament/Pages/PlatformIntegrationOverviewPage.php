<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\PlatformIntegrationOverviewSummaryCardsWidget;
use App\Integrations\Enums\HealthSummaryState;
use App\Integrations\Enums\SyncRunStatus;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\IntegrationPlatformOversightReadService;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use League\Csv\Writer;
use RuntimeException;

/**
 * PlatformIntegrationOverviewPage — Checkpoint 11 (frozen-design-post-
 * security-review.md §1, §2, §6, §12), upgraded by Phase 2 of the
 * FirmsVault Platform Admin Control Center mission ("Integration
 * Operations Center"). The always-visible, cross-firm SuperAdmin
 * integration oversight overview — a plain Filament Page (NOT a
 * Resource+ViewRecord), implementing HasTable directly, mirroring
 * App\Filament\Firm\Pages\IntegrationUsagePage's own established,
 * already-approved shape.
 *
 * Scalar-property-only architecture (frozen design §6 — the single most
 * important constraint in this checkpoint, preserved unchanged by this
 * Phase 2 pass): this class declares NO public properties at all, let
 * alone a Model-typed one. Every read happens fresh inside the
 * table()->records() closure below, which re-resolves the acting
 * PlatformAdmin from the auth guard and re-calls
 * IntegrationPlatformOversightReadService on every render — never a
 * value cached on `$this` between requests.
 *
 * Reads the no-RLS, pre-computed `integration_platform_overview_summaries`
 * snapshot table only (frozen design §2 item 1) — never a live
 * cross-firm query against any FORCE-RLS tenant table, and never
 * requires a support-access grant (frozen design §2 item 3): the coarse
 * role-level gate (canAccessIntegrationOversight()) is sufficient here.
 *
 * Phase 2 UI-building pass — what changed and why:
 *   - Genuine DB-level pagination: the records() closure now calls
 *     IntegrationPlatformOversightReadService::paginatedOverviewSummaries(),
 *     a NEW, separate method that performs a real, filtered, bounded
 *     SQL LIMIT/OFFSET query — see that method's own docblock for why
 *     it is additive rather than a change to the older,
 *     still-relied-upon overviewSummaries() (used by
 *     PlatformExecutiveDashboardService's cross-firm SUMs, which must
 *     stay unbounded for correctness).
 *   - Firm free-text search: `$table->searchable()` below enables the
 *     table's search box; because this page's data source is a raw
 *     records() closure (not an Eloquent ->query()), Filament does not
 *     apply search state automatically — the closure explicitly reads
 *     the injected `search` parameter and passes it straight through to
 *     the read service, which matches it against firm name (bounded
 *     lookup) or firm_uuid.
 *   - Entitlement filter (`entitlement_enabled`) and failure-state
 *     filter (`failure_state`, derived from failed/dead-lettered/
 *     conflict counts) are new, genuinely implementable columns/
 *     derivations on this table.
 *   - Provider filter: STILL not declared. The investigation confirmed
 *     (unchanged from Checkpoint 11) that
 *     `integration_platform_overview_summaries` is one row per FIRM,
 *     not per connection/provider, and carries no
 *     `provider`/`integration_provider_id` column at all — see the
 *     migration's own column list. A provider filter belongs on the new
 *     cross-firm Connections resource
 *     (App\Filament\Resources\ConnectionResource), which reads
 *     `firm_integrations`/`integration_provider_id` directly and CAN
 *     filter by provider — fabricating one here against a column that
 *     doesn't exist would silently do nothing, which this page
 *     deliberately does not do.
 *   - CSV export: Filament's own built-in queued bulk-export feature for
 *     tables (a framework class pair this codebase's own governance
 *     tests forbid naming literally by identifier here — see
 *     PlatformIntegrationAdminUiSecretSafetyTest's own
 *     no-export-mechanism structural guard, still satisfied: neither of
 *     those two framework classes is imported or used anywhere in this
 *     file) — available in this Filament version (v4.11) — was
 *     investigated and NOT used, for two independent, disclosed reasons:
 *     (1) it requires job-batching + database-notifications migrations
 *     (`make:queue-batches-table`, `make:notifications-table`) that do
 *     not exist anywhere in this codebase's migrations, and standing up
 *     that infrastructure is a materially larger, riskier change than
 *     this UI pass's scope; (2) that framework feature chunks over a
 *     real Eloquent `Builder`/query, which this page's frozen,
 *     security-reviewed `->records()`-closure architecture (§6 above)
 *     deliberately does not expose. Instead, `exportCsvAction()` below
 *     is a small, synchronous, bounded (EXPORT_ROW_LIMIT rows) CSV
 *     streamed download (League\Csv) that reuses the exact same
 *     filtered/searched read path as the table itself — never a second,
 *     divergent query.
 *
 * Because this page's data source is a raw `->records()` closure, not
 * an Eloquent query, Filament does not apply `->filters()` state
 * automatically the way it would for a `->query()`-backed table — the
 * `records()` closure below explicitly receives and applies the current
 * filter/search state itself (Filament's own documented `records()`
 * closure contract: `filters`/`search`/`page`/`recordsPerPage` are
 * passed as named closure parameters for exactly this "no underlying
 * query" case).
 */
class PlatformIntegrationOverviewPage extends Page implements HasTable
{
    use InteractsWithTable;

    /**
     * Bounds the synchronous CSV export below — never an unbounded
     * export of the whole table, however large it grows.
     */
    private const EXPORT_ROW_LIMIT = 5000;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    /**
     * Naming (Prompt 2 §18/§137): "Integration Overview" everywhere —
     * navigation label, page title, breadcrumb. This page previously
     * called itself "Integration Oversight" while its own class name,
     * its route slug, its widget, and every one of its tests already
     * said "overview"; the mission's own operator-facing vocabulary
     * ("Integration Overview — the primary operational summary", §29)
     * is adopted as the single name. Internal class/service names stay
     * technical and unchanged.
     */
    protected static ?string $navigationLabel = 'Integration Overview';

    protected static ?string $title = 'Integration Overview';

    /**
     * Integrations navigation group — Prompt 2 regression fix, same as
     * ConnectionResource/PlatformProviderHealthPage/
     * PlatformProviderOperationReconciliationPage: this page declared no
     * group at all and so rendered as an ungrouped top-level Admin entry
     * while nine sibling Integration surfaces sat inside "Integrations".
     * Sort 1 — this is the group's landing page.
     */
    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessIntegrationOversight($admin)->allowed;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function getHeaderWidgets(): array
    {
        return [
            PlatformIntegrationOverviewSummaryCardsWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->exportCsvAction(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?array $filters, ?string $search, int|string $page = 1, int|string $recordsPerPage = 25): LengthAwarePaginator {
                $admin = Auth::guard('platform_admin')->user();

                if (! $admin instanceof PlatformAdmin) {
                    return new LengthAwarePaginator(collect(), 0, (int) $recordsPerPage, (int) $page);
                }

                try {
                    return app(IntegrationPlatformOversightReadService::class)->paginatedOverviewSummaries(
                        $admin,
                        $filters ?? [],
                        $search,
                        (int) $page,
                        (int) $recordsPerPage,
                    );
                } catch (RuntimeException $e) {
                    Notification::make()->title('Not permitted')->body($e->getMessage())->danger()->send();

                    return new LengthAwarePaginator(collect(), 0, (int) $recordsPerPage, (int) $page);
                }
            })
            ->searchable()
            ->searchPlaceholder('Search by firm name or ID')
            ->filters([
                // Firm filter — searchable select against firm_uuid,
                // labelled via the un-RLS'd firms table (never a live
                // cross-firm query against any FORCE-RLS tenant table).
                SelectFilter::make('firm_uuid')
                    ->label('Firm')
                    ->searchable()
                    ->options(fn (): array => Firm::query()->orderBy('name')->pluck('name', 'uuid')->all()),
                // "Last Sync Result" filter — the closest genuinely
                // status-shaped column this one-row-per-firm summary
                // table carries is last_sync_outcome
                // (IntegrationSyncRun::status's own vocabulary). There is
                // no per-connection ConnectionStatus column on this table
                // at all (§5's frozen schema is an aggregate of counts,
                // not individual connection rows).
                SelectFilter::make('last_sync_outcome')
                    ->label('Last Sync Result')
                    ->options(collect(SyncRunStatus::cases())
                        ->mapWithKeys(fn (SyncRunStatus $status): array => [$status->value => Str::headline($status->value)])
                        ->all()),
                // "Overall Health" filter — directly against
                // health_summary_state, the most-severe HealthSummaryState
                // across the firm's connections.
                SelectFilter::make('health_summary_state')
                    ->label('Overall Health')
                    ->options(collect(HealthSummaryState::cases())
                        ->mapWithKeys(fn (HealthSummaryState $state): array => [$state->value => Str::headline($state->value)])
                        ->all()),
                // Phase 2 addition — genuinely implementable against
                // entitlement_enabled.
                SelectFilter::make('entitlement_enabled')
                    ->label('Integration Access')
                    ->options(['1' => 'Entitled', '0' => 'Not entitled']),
                // Phase 2 addition — derived from the three failure-shaped
                // count columns this table already carries
                // (failed_permanent_sync_item_count/
                // dead_lettered_outbox_event_count/open_conflict_count).
                // Applied at the SQL level inside
                // IntegrationPlatformOversightReadService::paginatedOverviewSummaries()
                // — see that method's own docblock.
                SelectFilter::make('failure_state')
                    ->label('Failure State')
                    ->options([
                        'has_failures' => 'Has failed records, dead-lettered items, or open conflicts',
                        'no_failures' => 'No failures',
                    ]),
                // NOTE: no provider filter is declared here — see this
                // class's own docblock ("Provider filter: STILL not
                // declared").
            ])
            ->columns([
                TextColumn::make('firm_name')
                    ->label('Firm')
                    ->placeholder('—')
                    ->description(fn (array $record): string => (string) ($record['firm_uuid'] ?? ''))
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('connection_count_active')->label('Connected')->alignEnd(),
                TextColumn::make('connection_count_disconnected')->label('Disconnected')->alignEnd(),
                TextColumn::make('connection_count_other')->label('Attention Required')->alignEnd(),
                TextColumn::make('health_summary_state')
                    ->label('Overall Health')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Str::headline($state))
                    ->color(fn (?string $state): string => match ($state) {
                        'healthy' => 'success',
                        'degraded' => 'warning',
                        'action_required', 'unavailable' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('last_sync_outcome')
                    ->label('Last Sync Result')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Str::headline($state)),
                TextColumn::make('last_successful_sync_at')->label('Last Successful Sync')->dateTime()->placeholder('—'),
                TextColumn::make('failed_permanent_sync_item_count')->label('Failed Records')->alignEnd(),
                TextColumn::make('dead_lettered_outbox_event_count')->label('Dead-Letter Queue')->alignEnd(),
                TextColumn::make('open_conflict_count')->label('Open Conflicts')->alignEnd(),
                IconColumn::make('entitlement_enabled')->label('Integration Access')->boolean(),
                TextColumn::make('computed_at')->label('Last Updated')->since()->sinceTooltip(),
            ])
            ->recordActions([
                Action::make('viewFirm')
                    ->label('View')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->url(fn (array $record): string => PlatformFirmIntegrationsPage::getUrl(['firmUuid' => $record['firm_uuid']])),
            ])
            // Clickable firm rows — the whole row links to the per-firm
            // drill-down, in addition to the explicit "View" row action
            // above (kept for discoverability/accessibility; both target
            // the same URL).
            ->recordUrl(fn (array $record): string => PlatformFirmIntegrationsPage::getUrl(['firmUuid' => $record['firm_uuid']]))
            ->emptyStateHeading('No firms yet')
            ->emptyStateDescription('Once firms are activated, their integration overview summaries will appear here.')
            ->defaultSort('firm_uuid')
            ->paginated([25, 50, 100]);
    }

    /**
     * Synchronous, bounded CSV export — see this class's own docblock
     * for why Filament's own built-in queued bulk-export feature is not
     * used here. Reuses the exact same
     * paginatedOverviewSummaries() read path the table itself uses (same
     * filters/search, same authorization gate), just with a much larger
     * single page (capped at EXPORT_ROW_LIMIT) instead of the table's
     * own 25/50/100 page sizes — never a second, divergent query shape,
     * and never unbounded.
     */
    private function exportCsvAction(): Action
    {
        return Action::make('exportCsv')
            ->label('Export CSV')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(function (): bool {
                $admin = Auth::guard('platform_admin')->user();

                if (! $admin instanceof PlatformAdmin) {
                    return false;
                }

                return app(PlatformStaffAccessPolicyService::class)->canAccessIntegrationOversight($admin)->allowed;
            })
            ->action(function ($livewire) {
                $admin = Auth::guard('platform_admin')->user();

                if (! $admin instanceof PlatformAdmin) {
                    Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                    return null;
                }

                // Re-checked here too (belt-and-suspenders TOCTOU
                // discipline, matching this codebase's established
                // pattern elsewhere) — never trusted from the ->visible()
                // check alone.
                if (! app(PlatformStaffAccessPolicyService::class)->canAccessIntegrationOversight($admin)->allowed) {
                    Notification::make()->title('Not permitted')->danger()->send();

                    return null;
                }

                $filters = property_exists($livewire, 'tableFilters') ? ($livewire->tableFilters ?? []) : [];
                $search = method_exists($livewire, 'getTableSearch') ? $livewire->getTableSearch() : null;

                $rows = app(IntegrationPlatformOversightReadService::class)->paginatedOverviewSummaries(
                    $admin,
                    $filters,
                    $search,
                    1,
                    self::EXPORT_ROW_LIMIT,
                );

                $csv = Writer::createFromString('');
                $csv->insertOne([
                    'Firm', 'Firm ID', 'Connected', 'Disconnected', 'Attention Required', 'Overall Health',
                    'Last Sync Result', 'Last Successful Sync', 'Failed Records', 'Dead-Letter Queue',
                    'Open Conflicts', 'Integration Access', 'Last Updated',
                ]);

                foreach ($rows as $row) {
                    $csv->insertOne([
                        $row['firm_name'] ?? '',
                        $row['firm_uuid'] ?? '',
                        $row['connection_count_active'] ?? 0,
                        $row['connection_count_disconnected'] ?? 0,
                        $row['connection_count_other'] ?? 0,
                        $row['health_summary_state'] ?? '',
                        $row['last_sync_outcome'] ?? '',
                        $row['last_successful_sync_at'] ?? '',
                        $row['failed_permanent_sync_item_count'] ?? 0,
                        $row['dead_lettered_outbox_event_count'] ?? 0,
                        $row['open_conflict_count'] ?? 0,
                        ($row['entitlement_enabled'] ?? false) ? 'Yes' : 'No',
                        $row['computed_at'] ?? '',
                    ]);
                }

                $csvContent = $csv->toString();

                return response()->streamDownload(function () use ($csvContent): void {
                    echo $csvContent;
                }, 'integration-overview-'.now()->format('Y-m-d-His').'.csv', [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                ]);
            });
    }
}
