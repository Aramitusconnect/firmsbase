<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\ConnectionResource;
use App\Filament\Support\Integrations\IntegrationDisplay;
use App\Models\IntegrationPlatformProviderHealthSummary;
use App\Models\PlatformAdmin;
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
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * PlatformProviderHealthPage — Phase 2 of the FirmsVault Platform Admin
 * Control Center mission ("Integration Operations Center"). A
 * read-only, always-visible top-level nav page over the new
 * `integration_platform_provider_health_summaries` table (built in the
 * Phase 2 backend-foundations pass — see that table's own create
 * migration, database/migrations/2026_09_11_110001_create_integration_platform_provider_health_summaries_table.php,
 * for its exact, verified schema).
 *
 * Unlike PlatformIntegrationOverviewPage/PlatformFirmIntegrationsPage
 * (which must use a raw ->records() closure because their underlying
 * tables are either a FORCE-RLS'd per-firm table or require per-request
 * re-verification discipline against a broader access chokepoint), this
 * page's table uses an ordinary Eloquent ->query() — genuinely safe here
 * because `integration_platform_provider_health_summaries` carries NO
 * RLS/FORCE RLS at all (see that table's own "WHY THIS TABLE HAS NO RLS
 * AND NO FORCE RLS" migration docblock) and is not tenant-scoped in any
 * way, exactly mirroring FirmResource's own "plain, no-RLS table -> plain
 * ->query()-backed table" precedent. Real, genuine DB-level LIMIT/OFFSET
 * pagination (Eloquent's own ->paginate(), driven by Filament's table
 * pagination controls) rather than an in-PHP-materialized Collection.
 *
 * Gate: canAccessIntegrationOversight() — the same gate every other
 * Integration Operations Center page/resource in this pass uses.
 *
 * Structurally read-only: NO live provider calls are made anywhere in
 * this class (confirmed by inspection — every column below reads a
 * column already present on this cached summary row; nothing in this
 * class makes an HTTP/network call of any kind). This is a mission-
 * level, non-negotiable constraint (an Admin panel cannot safely
 * perform a live third-party OAuth/API call), not merely an
 * implementation choice — the ONLY writer of this table is the
 * scheduled RefreshIntegrationPlatformProviderHealthSummaryJob (via
 * IntegrationPlatformProviderHealthSummaryService::refreshForProvider()),
 * never this page.
 */
class PlatformProviderHealthPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $navigationLabel = 'Provider Health';

    protected static ?string $title = 'Provider Health';

    /**
     * Integrations navigation group — Prompt 2 regression fix (this page
     * declared none and rendered ungrouped at the top level).
     */
    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 3;

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
            ->query(function (): Builder {
                // Re-checked here too (not merely at canAccess()/mount
                // time), matching every other Checkpoint 11/Phase 2
                // page's own established discipline — an unauthorized
                // caller (or a caller whose role was revoked mid-session)
                // gets an empty, always-safe query rather than any real
                // rows, never trusting the page-load-time canAccess()
                // check alone.
                $admin = Auth::guard('platform_admin')->user();

                if (! $admin instanceof PlatformAdmin) {
                    return IntegrationPlatformProviderHealthSummary::query()->whereRaw('1 = 0');
                }

                if (! app(PlatformStaffAccessPolicyService::class)->canAccessIntegrationOversight($admin)->allowed) {
                    return IntegrationPlatformProviderHealthSummary::query()->whereRaw('1 = 0');
                }

                return IntegrationPlatformProviderHealthSummary::query();
            })
            ->filters([
                // Boolean-native filter (never a SelectFilter with
                // string '1'/'0' option keys against a real boolean
                // column) — this table's query is a genuine Eloquent
                // Builder, so Filament applies this filter's ->query()
                // automatically; TernaryFilter's own built-in
                // ->boolean() query shape is the correct, type-safe way
                // to filter a boolean column.
                TernaryFilter::make('provider_enabled')
                    ->label('Enabled'),
                // Provider filter keyed on the stored `provider_code`
                // but LABELLED from the canonical registry — an operator
                // picks "Google Workspace", never "googleworkspace".
                SelectFilter::make('provider_code')
                    ->label('Provider')
                    ->options(fn (): array => IntegrationDisplay::providerFilterOptions()),
                // Attention state — deliberately a THREE-option select,
                // not a ternary yes/no. A row whose signals are all NULL
                // has never been evaluated: it is neither "requires
                // attention" nor "healthy", and a two-way filter would
                // make those rows silently invisible under BOTH options
                // (SQL NOT IN never matches NULL), which is precisely the
                // "unmonitored looks like fine" failure this console must
                // not have. Every branch is real SQL against columns this
                // table genuinely carries — never a PHP post-filter.
                SelectFilter::make('attention_state')
                    ->label('Attention state')
                    ->options([
                        'requires_attention' => 'Requires attention',
                        'healthy' => 'Healthy (measured)',
                        'not_checked' => 'Not checked yet',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'requires_attention' => $query->where(function (Builder $inner): void {
                                $inner->where('firms_requiring_attention_count', '>', 0)
                                    ->orWhereIn('oauth_health_signal', self::NON_HEALTHY_SIGNALS)
                                    ->orWhereIn('webhook_health_signal', self::NON_HEALTHY_SIGNALS)
                                    ->orWhereIn('rate_limit_condition_signal', self::NON_HEALTHY_SIGNALS);
                            }),
                            'healthy' => $query->where('firms_requiring_attention_count', '=', 0)
                                ->where(function (Builder $inner): void {
                                    // At least one signal was actually
                                    // measured, and none of them is
                                    // non-healthy.
                                    $inner->whereNotNull('oauth_health_signal')
                                        ->orWhereNotNull('webhook_health_signal')
                                        ->orWhereNotNull('rate_limit_condition_signal');
                                })
                                ->where(function (Builder $inner): void {
                                    $inner->whereNull('oauth_health_signal')->orWhereNotIn('oauth_health_signal', self::NON_HEALTHY_SIGNALS);
                                })
                                ->where(function (Builder $inner): void {
                                    $inner->whereNull('webhook_health_signal')->orWhereNotIn('webhook_health_signal', self::NON_HEALTHY_SIGNALS);
                                })
                                ->where(function (Builder $inner): void {
                                    $inner->whereNull('rate_limit_condition_signal')->orWhereNotIn('rate_limit_condition_signal', self::NON_HEALTHY_SIGNALS);
                                }),
                            'not_checked' => $query->whereNull('oauth_health_signal')
                                ->whereNull('webhook_health_signal')
                                ->whereNull('rate_limit_condition_signal'),
                            default => $query,
                        };
                    }),
            ])
            ->columns([
                // Canonical display label, not the raw stored slug. The
                // raw code is kept as the row description so an operator
                // correlating with a log line or an API response can
                // still see it — it is simply no longer the primary,
                // customer-facing name (§35/§116).
                TextColumn::make('provider_code')
                    ->label('Provider')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (?string $state): string => IntegrationDisplay::labelForProviderCode($state))
                    ->description(fn (IntegrationPlatformProviderHealthSummary $record): string => IntegrationDisplay::isInternalProviderCode($record->provider_code)
                        ? $record->provider_code.' — internal fixture provider, not customer-supported'
                        : (string) $record->provider_code),
                // Derived, never stored: the most severe of the three
                // real signals, with "Disabled" and "Not checked"
                // treated as first-class states rather than collapsed
                // into a health colour. See overallHealthLabel().
                TextColumn::make('overall_health')
                    ->label('Overall Health')
                    ->badge()
                    ->state(fn (IntegrationPlatformProviderHealthSummary $record): string => self::overallHealthLabel($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Healthy' => 'success',
                        'Degraded' => 'warning',
                        'Action Required' => 'danger',
                        'Disabled' => 'gray',
                        default => 'gray',
                    })
                    ->tooltip(fn (IntegrationPlatformProviderHealthSummary $record): string => self::overallHealthExplanation($record)),
                IconColumn::make('provider_enabled')->label('Enabled')->boolean()->sortable(),
                TextColumn::make('connected_firm_count')->label('Connected Firms')->alignEnd()->sortable(),
                TextColumn::make('firms_requiring_attention_count')
                    ->label('Firms Requiring Attention')
                    ->alignEnd()
                    ->sortable()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'success'),
                // computed_at is NOT NULL on this table, so a row's mere
                // existence proves it was evaluated — "Last Checked" is
                // always a real measurement here, never a placeholder.
                TextColumn::make('computed_at')->label('Last Checked')->since()->sinceTooltip()->sortable(),

                // ---- Secondary dimensions: real, but not worth the
                // horizontal scroll by default (§19). Every one of them
                // names its own absence explicitly instead of "—".
                TextColumn::make('disconnected_firm_count')->label('Disconnected Firms')->alignEnd()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('oauth_health_signal')
                    ->label('OAuth / Credential Health')
                    ->badge()
                    ->formatStateUsing(fn (?string $state, IntegrationPlatformProviderHealthSummary $record): string => IntegrationDisplay::healthSignal($state, self::absentSignalLabel($record)))
                    ->color(fn (IntegrationPlatformProviderHealthSummary $record): string => IntegrationDisplay::healthColor($record->oauth_health_signal))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('webhook_health_signal')
                    ->label('Webhook Health')
                    ->badge()
                    ->formatStateUsing(fn (?string $state, IntegrationPlatformProviderHealthSummary $record): string => IntegrationDisplay::healthSignal($state, self::absentSignalLabel($record)))
                    ->color(fn (IntegrationPlatformProviderHealthSummary $record): string => IntegrationDisplay::healthColor($record->webhook_health_signal))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('rate_limit_condition_signal')
                    ->label('Rate-Limit Health')
                    ->badge()
                    ->formatStateUsing(fn (?string $state, IntegrationPlatformProviderHealthSummary $record): string => IntegrationDisplay::healthSignal($state, self::absentSignalLabel($record)))
                    ->color(fn (IntegrationPlatformProviderHealthSummary $record): string => IntegrationDisplay::healthColor($record->rate_limit_condition_signal))
                    ->toggleable(isToggledHiddenByDefault: true),
                // Checkpoint 1 (FirmsVault Live Integrations,
                // checkpoint1-design-health-sandbox.md §A.3.2/§A.4)
                // additions — one TextColumn per new metrics column.
                TextColumn::make('total_request_count')->label('Total Requests')->alignEnd()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total_success_count')
                    ->label('Success Rate')
                    ->alignEnd()
                    // A provider with zero recorded requests has no
                    // success RATE — reporting "0%" would read as "every
                    // call failed", the exact misleading-zero this
                    // mission forbids. It is genuinely unmeasured.
                    ->formatStateUsing(function (?int $state, IntegrationPlatformProviderHealthSummary $record): string {
                        $total = (int) $record->total_request_count;

                        if ($total === 0) {
                            return IntegrationDisplay::NO_DATA_YET;
                        }

                        $successes = (int) $state;

                        return round(($successes / $total) * 100, 1).'% ('.$successes.'/'.$total.')';
                    }),
                TextColumn::make('throttled_connection_count')->label('Throttled Connections')->alignEnd()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('token_refresh_failure_count')->label('Token Refresh Failures')->alignEnd()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('webhook_verification_failure_count')->label('Webhook Verification Failures (24h)')->alignEnd()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('dead_letter_count')->label('Dead-Lettered Events')->alignEnd()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('avg_latency_ms')
                    ->label('Avg Latency (ms)')
                    ->alignEnd()
                    ->sortable()
                    // NULL here means "no latency sample was recorded in
                    // this window" (see the summary service's own
                    // $latencySampleCount guard), which is categorically
                    // different from "0 ms".
                    ->formatStateUsing(fn (?int $state): string => $state === null ? IntegrationDisplay::NOT_MEASURED : (string) $state)
                    ->toggleable(isToggledHiddenByDefault: true),
                // Pre-existing-defect fix (was covered by a deliberate
                // "known bug currently throws a 500" test, now flipped to
                // assert the fix): this column previously used
                // ->formatStateUsing(?array $state), but Filament unwraps
                // a single-element array before handing state to the
                // callback — so a summary map with EXACTLY ONE error
                // category passed an int where an ?array was declared and
                // the whole Provider Health page 500'd. A page whose job
                // is to report provider trouble crashing precisely when a
                // provider has exactly one kind of error is the worst
                // possible failure mode for this screen (§28).
                //
                // Fixed at the source rather than by widening the
                // signature: ->state() reads the cast array straight off
                // the model and returns an already-formatted STRING, so
                // Filament never sees an array for this column at all and
                // there is nothing left to unwrap.
                TextColumn::make('recent_error_classification_summary')
                    ->label('Recent Error Classifications')
                    ->state(function (IntegrationPlatformProviderHealthSummary $record): string {
                        $summary = $record->recent_error_classification_summary;

                        if (! is_array($summary) || $summary === []) {
                            return 'No classified errors';
                        }

                        return collect($summary)
                            ->map(fn (mixed $count, string|int $category): string => "{$category}: {$count}")
                            ->implode(', ');
                    })
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('viewConnections')
                    ->label('Connections')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->color('gray')
                    ->tooltip('Open Connections filtered to this provider')
                    // Cross-link (§23/§31): Provider Health -> the real
                    // per-connection rows behind these aggregates. The
                    // target filter keys on integration_providers.id,
                    // which is exactly what this row's soft
                    // `integration_provider_id` reference carries.
                    ->url(fn (IntegrationPlatformProviderHealthSummary $record): string => ConnectionResource::getUrl('index', [
                        'tableFilters' => [
                            'integration_provider_id' => ['value' => $record->integration_provider_id],
                        ],
                    ])),
            ])
            ->recordAction(null)
            ->recordUrl(null)
            ->emptyStateHeading('Provider health has not been measured yet')
            ->emptyStateDescription('Provider health summaries are refreshed every 5 minutes once at least one firm has connected to a provider. No provider has been evaluated yet — this is not the same as every provider being healthy.')
            ->defaultSort('provider_code')
            ->paginated([25, 50, 100]);
    }

    /**
     * The three stored signals that mean "not healthy". Kept as one
     * constant so the attention filter's true/false branches can never
     * drift apart from each other.
     */
    private const NON_HEALTHY_SIGNALS = [
        'degraded',
        'action_required',
        'unavailable',
    ];

    /**
     * Derived overall health — the most severe of the three real stored
     * signals, with two states that are NOT health at all surfaced
     * explicitly instead of being coloured as if they were:
     *
     *   - "Disabled": the provider is switched off, so its signals say
     *     nothing about whether it would be healthy if enabled.
     *   - "Not checked": all three signals are null, which this table's
     *     own writer produces when no connected firm has yet yielded
     *     health data. Rendering that as "Healthy" would be a fabricated
     *     all-clear (§33/§37) — the single most dangerous thing a
     *     health console can do.
     *
     * Nothing is computed from a live provider call; every input is a
     * column already on the passed row.
     */
    private static function overallHealthLabel(IntegrationPlatformProviderHealthSummary $record): string
    {
        if (! $record->provider_enabled) {
            return 'Disabled';
        }

        $signals = array_filter([
            $record->oauth_health_signal,
            $record->webhook_health_signal,
            $record->rate_limit_condition_signal,
        ], fn (?string $signal): bool => is_string($signal) && trim($signal) !== '');

        if ($signals === []) {
            return IntegrationDisplay::NOT_CHECKED;
        }

        foreach (['unavailable', 'action_required', 'degraded'] as $severe) {
            if (in_array($severe, $signals, true)) {
                return Str::headline($severe);
            }
        }

        return 'Healthy';
    }

    /**
     * Plain-language "why does it say that" for the derived badge —
     * shown as a tooltip so the derivation is never a black box.
     */
    private static function overallHealthExplanation(IntegrationPlatformProviderHealthSummary $record): string
    {
        if (! $record->provider_enabled) {
            return 'This provider is disabled. Its health signals are not evaluated while it is off.';
        }

        $parts = [];

        foreach ([
            'OAuth/credential' => $record->oauth_health_signal,
            'Webhook' => $record->webhook_health_signal,
            'Rate limit' => $record->rate_limit_condition_signal,
        ] as $dimension => $signal) {
            $parts[] = $dimension.': '.IntegrationDisplay::healthSignal($signal, self::absentSignalLabel($record));
        }

        return implode(' · ', $parts).' · Last checked '.$record->computed_at?->toDayDateTimeString();
    }

    /**
     * Names WHY a signal is null on this specific row, rather than
     * printing one ambiguous placeholder for two different facts. The
     * summary writer returns null for every signal precisely when the
     * provider has no connected firm carrying health data — so with zero
     * connected firms there is nothing to check, and with connected
     * firms present the check simply has not produced data yet.
     */
    private static function absentSignalLabel(IntegrationPlatformProviderHealthSummary $record): string
    {
        return ((int) $record->connected_firm_count) === 0
            ? 'No connected firms'
            : IntegrationDisplay::NEVER_CHECKED;
    }
}
