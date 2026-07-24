<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Integrations\Enums\HealthSummaryState;
use App\Integrations\Enums\SyncRunStatus;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\IntegrationPlatformOversightReadService;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Actions\Action;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * PlatformIntegrationOverviewPage — Checkpoint 11 (frozen-design-post-
 * security-review.md §1, §2, §6, §12). The always-visible, cross-firm
 * SuperAdmin integration oversight overview — a plain Filament Page (NOT
 * a Resource+ViewRecord), implementing HasTable directly, mirroring
 * App\Filament\Firm\Pages\IntegrationUsagePage's own established,
 * already-approved shape.
 *
 * Scalar-property-only architecture (frozen design §6 — the single most
 * important constraint in this checkpoint): this class declares NO
 * public properties at all, let alone a Model-typed one. Every read
 * happens fresh inside the table()->records() closure below, which
 * re-resolves the acting PlatformAdmin from the auth guard and re-calls
 * IntegrationPlatformOversightReadService::overviewSummaries() on every
 * render — never a value cached on `$this` between requests.
 *
 * Reads the no-RLS, pre-computed `integration_platform_overview_summaries`
 * snapshot table only (frozen design §2 item 1) — never a live
 * cross-firm query against any FORCE-RLS tenant table, and never
 * requires a support-access grant (frozen design §2 item 3): the coarse
 * role-level gate (canAccessIntegrationOversight()) is sufficient here.
 *
 * Filtering (frozen design's own "filterable by firm/provider/status/
 * health" requirement): a firm filter (searchable select against
 * `firm_uuid`, labelled from the un-RLS'd `firms` table), a status
 * filter (against `last_sync_outcome`, the closest status-shaped column
 * this one-row-per-firm summary table actually carries), and a health
 * filter (against `health_summary_state`) are all genuinely
 * implementable against the frozen §5 schema and are wired below. A
 * true per-PROVIDER filter is NOT implementable against this table
 * without a schema change: `integration_platform_overview_summaries` is
 * one row per FIRM, not per connection/provider, and carries no
 * `provider`/`integration_provider_id` column at all (confirmed against
 * the migration) — adding one is out of this fix's authorized scope, so
 * no provider filter is declared here rather than fabricating one
 * against a column that doesn't exist.
 *
 * Because this page's data source is a raw `Collection` closure
 * (`->records()`), not an Eloquent query, Filament does not apply
 * `->filters()` state automatically the way it would for a `->query()`-
 * backed table — the `records()` closure below explicitly receives and
 * applies the current filter state itself (Filament's own documented
 * `records()` closure contract: `filters`/`search`/`sort` are passed as
 * named closure parameters for exactly this "no underlying query"
 * case).
 */
class PlatformIntegrationOverviewPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $navigationLabel = 'Integration Oversight';

    protected static ?string $title = 'Integration Oversight';

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

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?array $filters): Collection {
                $admin = Auth::guard('platform_admin')->user();

                if (! $admin instanceof PlatformAdmin) {
                    return collect();
                }

                // Re-checked here too (not merely at canAccess()/mount
                // time), matching IntegrationUsagePage's own established
                // discipline — hydrateCanAuthorizeAccess() already
                // re-runs canAccess() on every Livewire request, but
                // this closure independently re-asserts regardless.
                $summaries = app(IntegrationPlatformOversightReadService::class)->overviewSummaries($admin);

                // This table's data source is a raw Collection, not an
                // Eloquent query, so ->filters() state is NOT applied
                // automatically the way it would be for a ->query()-
                // backed table — it must be read from the $filters
                // named injection and applied here explicitly.
                $filters ??= [];
                $firmUuid = $filters['firm_uuid']['value'] ?? null;
                $lastSyncOutcome = $filters['last_sync_outcome']['value'] ?? null;
                $healthSummaryState = $filters['health_summary_state']['value'] ?? null;

                return $summaries
                    ->when(filled($firmUuid), fn (Collection $rows): Collection => $rows->where('firm_uuid', $firmUuid))
                    ->when(filled($lastSyncOutcome), fn (Collection $rows): Collection => $rows->where('last_sync_outcome', $lastSyncOutcome))
                    ->when(filled($healthSummaryState), fn (Collection $rows): Collection => $rows->where('health_summary_state', $healthSummaryState))
                    ->values();
            })
            ->filters([
                // Firm filter — searchable select against firm_uuid,
                // labelled via the un-RLS'd firms table (never a live
                // cross-firm query against any FORCE-RLS tenant table).
                SelectFilter::make('firm_uuid')
                    ->label('Firm')
                    ->searchable()
                    ->options(fn (): array => Firm::query()->orderBy('name')->pluck('name', 'uuid')->all()),
                // "Status" filter — the closest genuinely status-shaped
                // column this one-row-per-firm summary table carries is
                // last_sync_outcome (IntegrationSyncRun::status's own
                // vocabulary). There is no per-connection ConnectionStatus
                // column on this table at all (§5's frozen schema is an
                // aggregate of counts, not individual connection rows).
                SelectFilter::make('last_sync_outcome')
                    ->label('Status')
                    ->options(collect(SyncRunStatus::cases())
                        ->mapWithKeys(fn (SyncRunStatus $status): array => [$status->value => Str::headline($status->value)])
                        ->all()),
                // Health filter — directly against health_summary_state,
                // the most-severe HealthSummaryState across the firm's
                // connections.
                SelectFilter::make('health_summary_state')
                    ->label('Health')
                    ->options(collect(HealthSummaryState::cases())
                        ->mapWithKeys(fn (HealthSummaryState $state): array => [$state->value => Str::headline($state->value)])
                        ->all()),
                // NOTE: no provider filter is declared here — the frozen
                // §5 schema has no provider/integration_provider_id
                // column on this one-row-per-firm table at all, so a
                // true per-provider filter is not implementable against
                // it without a schema change, which is out of this fix's
                // authorized scope.
            ])
            ->columns([
                TextColumn::make('firm_uuid')->label('Firm')->limit(12)->fontFamily('mono'),
                TextColumn::make('connection_count_active')->label('Active')->alignEnd(),
                TextColumn::make('connection_count_disconnected')->label('Disconnected')->alignEnd(),
                TextColumn::make('connection_count_other')->label('Other')->alignEnd(),
                TextColumn::make('health_summary_state')
                    ->label('Health')
                    ->badge()
                    ->placeholder('—')
                    ->color(fn (?string $state): string => match ($state) {
                        'healthy' => 'success',
                        'degraded' => 'warning',
                        'action_required', 'unavailable' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('last_sync_outcome')->label('Last sync')->placeholder('—'),
                TextColumn::make('last_sync_at')->label('Last sync at')->dateTime()->placeholder('—'),
                TextColumn::make('failed_permanent_sync_item_count')->label('Failed items')->alignEnd(),
                TextColumn::make('dead_lettered_outbox_event_count')->label('Dead-lettered')->alignEnd(),
                TextColumn::make('open_conflict_count')->label('Open conflicts')->alignEnd(),
                IconColumn::make('entitlement_enabled')->label('Entitled')->boolean(),
                TextColumn::make('computed_at')->label('Computed at')->since()->sinceTooltip(),
            ])
            ->recordActions([
                Action::make('viewFirm')
                    ->label('View')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->url(fn (array $record): string => PlatformFirmIntegrationsPage::getUrl(['firmUuid' => $record['firm_uuid']])),
            ])
            ->emptyStateHeading('No firms yet')
            ->emptyStateDescription('Once firms are activated, their integration overview summaries will appear here.')
            ->defaultSort('firm_uuid')
            ->paginated([25, 50, 100]);
    }
}
