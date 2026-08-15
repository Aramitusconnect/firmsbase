<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\Integrations\IntegrationDisplay;
use App\Filament\Support\Integrations\ProviderKillSwitchScope;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\TimelineEvent;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\TenantContextService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * PlaidAnomalyOversightPage — FirmsVault Live Integrations, Checkpoint
 * 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §3). Lists
 * `provider_billing.anomaly_detected` `TimelineEvent` rows (written by
 * `DetectProviderUsageAnomaliesJob`), firm+product+count only, never
 * the underlying calls. `TimelineEvent` carries permanent FORCE ROW
 * LEVEL SECURITY, so cross-firm reads use the same per-firm-loop
 * pattern `PlatformConnectionDirectoryService`/
 * `PlatformPlaidCostOversightReadService` already establish.
 */
class PlaidAnomalyOversightPage extends Page implements HasTable
{
    use InteractsWithTable;

    private const MAX_FIRMS_SCANNED = 500;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    /**
     * Naming (§18/§137): the sidebar said "Plaid Anomalies" while the
     * page heading said "Plaid Usage Anomalies". One name now — the
     * fuller one, which is what the mission's own surface inventory
     * calls this screen and which reads unambiguously next to "Plaid
     * Cost Oversight" in the same group.
     */
    protected static ?string $navigationLabel = 'Plaid Usage Anomalies';

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Plaid Usage Anomalies';

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
        return $schema->components([EmbeddedTable::make()]);
    }

    /**
     * Firms whose tenant context could not be established during the
     * most recent render, surfaced in the subheading rather than
     * silently dropped (§15). Reset on every records() call.
     */
    public int $unreadableFirmCount = 0;

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?array $filters): Collection {
                $admin = Auth::guard('platform_admin')->user();

                if (! $admin instanceof PlatformAdmin) {
                    return collect();
                }

                if (! app(PlatformStaffAccessPolicyService::class)->canAccessIntegrationOversight($admin)->allowed) {
                    return collect();
                }

                $filters ??= [];
                $firmUuidFilter = $filters['firm_uuid']['value'] ?? null;
                $productFilter = $filters['product']['value'] ?? null;
                $from = $filters['detected_range']['from'] ?? null;
                $to = $filters['detected_range']['to'] ?? null;

                $tenantContext = new TenantContextService;
                $rows = collect();
                $unreadable = 0;

                Firm::query()
                    ->when(filled($firmUuidFilter), fn ($query) => $query->where('uuid', $firmUuidFilter))
                    ->orderBy('id')
                    ->limit(self::MAX_FIRMS_SCANNED)
                    ->get(['id', 'uuid', 'name'])
                    ->each(function (Firm $firm) use (&$rows, &$unreadable, $tenantContext, $from, $to, $productFilter) {
                        // $firm->id, not $firm -- this model was loaded with a
                        // restricted column list (see ->get() above), which
                        // omits deployment_mode/organization_id that
                        // TenantContextResolver::resolveForFirm() needs.
                        // Passing the partial model directly throws; passing
                        // the id makes runWithFirmContext() re-fetch the full
                        // row. Mirrors the established, working precedent in
                        // DetectProviderUsageAnomaliesJob and
                        // ExpireStaleProviderReservationsJob.
                        //
                        // PER-FIRM FAILURE ISOLATION (Prompt 2, §28/§15):
                        // even with the id-passing fix above, this loop
                        // previously let ANY single firm's failure — a firm
                        // deleted mid-render, a row whose tenant context
                        // cannot be resolved — abort the whole render with a
                        // page-wide 500. One abnormal firm record must not
                        // take down cross-firm anomaly oversight for every
                        // other firm. The failure is counted and disclosed in
                        // the subheading, never silently swallowed.
                        try {
                            $events = $tenantContext->runWithFirmContext($firm->id, fn () => TimelineEvent::query()
                                ->where('firm_id', $firm->id)
                                ->where('event_type', 'provider_billing.anomaly_detected')
                                ->when(filled($from), fn ($query) => $query->where('occurred_at', '>=', CarbonImmutable::parse($from)->startOfDay()))
                                ->when(filled($to), fn ($query) => $query->where('occurred_at', '<=', CarbonImmutable::parse($to)->endOfDay()))
                                ->orderByDesc('occurred_at')
                                ->limit(20)
                                ->get());
                        } catch (Throwable) {
                            $unreadable++;

                            return;
                        }

                        foreach ($events as $event) {
                            $metadata = is_array($event->metadata_json) ? $event->metadata_json : [];

                            // Product lives inside the detection event's
                            // metadata JSON, so it is matched here rather
                            // than in SQL. This is bounded, not a
                            // full-table PHP filter: each firm's query is
                            // already limited to its 20 most recent
                            // detections above.
                            if (filled($productFilter) && ($metadata['product'] ?? null) !== $productFilter) {
                                continue;
                            }

                            $rows->push([
                                'id' => $event->uuid,
                                'firm_name' => $firm->name,
                                'firm_uuid' => $firm->uuid,
                                // A detection event that carries no product
                                // or no counts is a malformed observation,
                                // not a zero — naming it as such is the
                                // difference between "we saw no calls" and
                                // "we do not know what we saw" (§20).
                                'product' => $metadata['product'] ?? null,
                                'current_window_count' => $metadata['current_window_count'] ?? null,
                                'baseline_daily_average' => $metadata['baseline_daily_average'] ?? null,
                                'occurred_at' => $event->occurred_at,
                            ]);
                        }
                    });

                $this->unreadableFirmCount = $unreadable;

                return $rows->sortByDesc('occurred_at')->values();
            })
            ->filters([
                SelectFilter::make('firm_uuid')
                    ->label('Firm')
                    ->searchable()
                    ->options(fn (): array => Firm::query()->orderBy('name')->pluck('name', 'uuid')->all()),
                // Product options come from the code-defined billing
                // vocabulary, not a distinct() query: timeline_events
                // carries FORCE RLS with no cross-firm-read policy, so
                // there is no RLS-safe way to enumerate real values for
                // dropdown options from this zero-tenant-context panel.
                SelectFilter::make('product')
                    ->label('Plaid product')
                    ->options(fn (): array => ProviderKillSwitchScope::productOptions()),
                Filter::make('detected_range')
                    ->label('Detected between')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('to'),
                    ]),
            ])
            ->columns([
                TextColumn::make('firm_name')->label('Firm')->description(fn (array $record): string => (string) ($record['firm_uuid'] ?? '')),
                TextColumn::make('product')
                    ->label('Product')
                    ->formatStateUsing(fn (?string $state): string => IntegrationDisplay::orAbsent($state, 'Not recorded on this detection')),
                TextColumn::make('current_window_count')
                    ->label('Current Window Count')
                    ->alignEnd()
                    ->formatStateUsing(fn (mixed $state): string => $state === null ? IntegrationDisplay::NOT_MEASURED : (string) $state),
                TextColumn::make('baseline_daily_average')
                    ->label('Baseline Daily Average')
                    ->alignEnd()
                    ->formatStateUsing(fn (mixed $state): string => $state === null ? IntegrationDisplay::NOT_MEASURED : (string) $state),
                // Deviation is DERIVED from the two figures the detection
                // event genuinely recorded — never stored, never invented.
                // It is omitted entirely (not shown as 0%) whenever either
                // input is missing or the baseline is zero, since a
                // percentage against a zero baseline is undefined rather
                // than infinite.
                TextColumn::make('deviation')
                    ->label('Deviation')
                    ->alignEnd()
                    ->state(function (array $record): string {
                        $current = $record['current_window_count'] ?? null;
                        $baseline = $record['baseline_daily_average'] ?? null;

                        if (! is_numeric($current) || ! is_numeric($baseline)) {
                            return IntegrationDisplay::NOT_MEASURED;
                        }

                        if ((float) $baseline <= 0.0) {
                            return 'No baseline to compare against';
                        }

                        $deviation = (((float) $current - (float) $baseline) / (float) $baseline) * 100;

                        return ($deviation >= 0 ? '+' : '').round($deviation, 1).'%';
                    }),
                TextColumn::make('occurred_at')->label('Detected')->dateTime()->sortable(),
            ])
            ->emptyStateHeading('No anomalies detected')
            ->emptyStateDescription('Plaid usage anomalies are recorded by the scheduled detection job. An empty list means no anomaly was detected in the selected window — it does not mean detection has run for every firm.')
            ->paginated([25, 50]);
    }

    /**
     * Discloses partial coverage instead of presenting a partial scan as
     * a complete one (§15/§22).
     */
    public function getSubheading(): ?string
    {
        $notes = ['Anomalies are recorded by the scheduled Plaid usage detection job; this page reads those recorded detections and never evaluates usage itself.'];

        if ($this->unreadableFirmCount > 0) {
            $notes[] = sprintf(
                '%d firm(s) could not be evaluated during this render and are NOT represented below.',
                $this->unreadableFirmCount,
            );
        }

        return implode(' ', $notes);
    }
}
