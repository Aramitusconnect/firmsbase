<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\HealthCheckMonitoringType;
use App\Enums\HealthCheckStatus;
use App\Enums\HealthCheckType;
use App\Filament\Actions\Platform\RunHealthChecksNowAction;
use App\Models\HealthCheck;
use App\Models\PlatformAdmin;
use App\Services\HealthCheckRegistry;
use App\Services\OperationsHealthEvaluationService;
use App\Services\PlatformStaffAccessPolicyService;
use App\ValueObjects\ServiceHealthCurrentState;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * PlatformServiceHealthPage — the Operations console's Service Health
 * surface, over platform-wide `health_checks` rows (firm_id IS NULL;
 * the one firm-specific check type, TenantIsolationAnomalies, is
 * intentionally left to the existing Phase 1 Tenant Isolation page
 * rather than duplicated here).
 *
 * `health_checks` carries FORCE ROW LEVEL SECURITY with the
 * "nullable-firm_id, universal read" two-policy shape — firm_id IS
 * NULL rows are visible under the read policy regardless of active
 * tenant context, so this page's plain `whereNull('firm_id')` query
 * needs no runWithFirmContext()/runWithoutFirmContext() wrap.
 *
 * OPERATIONS CONTROL PLANE REBUILD. Three defects were corrected
 * here, all of the same family — the page was reassuring rather than
 * informative:
 *
 *  1. Five stub checks rendered as green "Healthy" badges. They now
 *     report Not Monitored (grey) at the source — see
 *     HealthCheckRegistry's own docblock.
 *  2. The disclosure text hardcoded "6 of the 9 registered check
 *     types" while naming only five, and would have gone stale the
 *     moment the registry changed. Every count on this page is now
 *     derived live from HealthCheckRegistry::monitoringTypeCounts().
 *  3. The page was a raw, paginated dump of the append-only
 *     observation log, sorted by id. That answers "what has ever
 *     been recorded," never "is the platform healthy right now."
 *     Current Health (one row per check, freshness-aware) is now the
 *     primary interface; the log is retained below it as history.
 *
 * Staleness is enforced on display: a Healthy observation older than
 * the freshness threshold shows as Unknown, not Healthy, so a stopped
 * monitoring pipeline can never keep the console green.
 */
class PlatformServiceHealthPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?string $navigationLabel = 'Service Health';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 81;

    protected static ?string $title = 'Service Health';

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessOperations($admin)->allowed;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->currentHealthSection(),
            $this->monitoringCoverageSection(),
            $this->historySection(),
            EmbeddedTable::make(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            RunHealthChecksNowAction::make(),
        ];
    }

    /**
     * Current Health — one line per registered check, showing the
     * effective (freshness-adjusted) status, what kind of evidence
     * produced it, and how old that evidence is. This is the primary
     * answer to "is FirmsVault healthy right now."
     */
    private function currentHealthSection(): Section
    {
        $evaluator = app(OperationsHealthEvaluationService::class);
        $summary = $evaluator->summary();

        $components = [
            Text::make($this->overallSummaryLine($summary))
                ->color($summary['overall']->color()),
        ];

        foreach ($evaluator->currentStates() as $state) {
            $components[] = Text::make($this->currentStateLine($state))
                ->color($state->effectiveStatus()->color());
        }

        $components[] = Text::make(sprintf(
            'Expected cadence: every %ds (health:checks:run). An observation older than %ds is treated as stale and '.
            'its status is shown as Unknown rather than carried forward. Derived history fields are computed from '.
            'each check\'s most recent %d observations.',
            $evaluator->expectedCadenceSeconds(),
            $evaluator->freshnessThresholdSeconds(),
            OperationsHealthEvaluationService::HISTORY_WINDOW,
        ))->color('gray');

        return Section::make('Current Health')
            ->icon(Heroicon::OutlinedHeart)
            ->description('Latest observation per check, adjusted for freshness. Not a log — see Check History below.')
            ->schema($components);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function overallSummaryLine(array $summary): string
    {
        return sprintf(
            'Overall: %s — %d healthy, %d degraded, %d critical, %d unknown, %d not monitored (of %d checks). '.
            '%d stale, %d never observed, %d requiring attention.',
            $summary['overall']->label(),
            $summary['healthy'],
            $summary['degraded'],
            $summary['critical'],
            $summary['unknown'],
            $summary['not_monitored'],
            $summary['total'],
            $summary['stale'],
            $summary['never_observed'],
            $summary['requires_attention'],
        );
    }

    private function currentStateLine(ServiceHealthCurrentState $state): string
    {
        $line = sprintf(
            '%s — %s [%s] · %s',
            Str::headline($state->checkType->value),
            $state->effectiveStatus()->label(),
            $state->monitoringType->label(),
            $state->hasHistory()
                ? sprintf('last checked %ds ago (%s)', $state->observationAgeSeconds, $state->freshness->label())
                : 'never checked',
        );

        if ($state->consecutiveFailures !== null && $state->consecutiveFailures > 0) {
            $line .= sprintf(' · %d consecutive failure(s)', $state->consecutiveFailures);
        }

        if ($state->detail !== null && $state->detail !== '') {
            $line .= ' · '.$state->detail;
        }

        return $line;
    }

    /**
     * Monitoring coverage — the honest census of what this platform
     * does and does not watch. Every number is derived from the
     * registry at render time; none of it is hardcoded prose.
     */
    private function monitoringCoverageSection(): Section
    {
        $registry = app(HealthCheckRegistry::class);
        $counts = $registry->monitoringTypeCounts();
        $total = count(HealthCheckType::cases());

        $unmonitored = collect(HealthCheckType::cases())
            ->filter(fn (HealthCheckType $type): bool => $registry->monitoringTypeFor($type) === HealthCheckMonitoringType::NotMonitored)
            ->map(fn (HealthCheckType $type): string => Str::headline($type->value))
            ->implode(', ');

        $components = [
            Text::make(sprintf(
                '%d check type(s) registered in total: %d live probe(s), %d internal metric(s), %d configuration '.
                'check(s), %d not monitored.',
                $total,
                $counts[HealthCheckMonitoringType::LiveProbe->value],
                $counts[HealthCheckMonitoringType::InternalMetric->value],
                $counts[HealthCheckMonitoringType::ConfigurationCheck->value],
                $counts[HealthCheckMonitoringType::NotMonitored->value],
            )),
        ];

        if ($unmonitored !== '') {
            $components[] = Text::make(
                'Not monitored (no probe of any kind exists behind these — they report Not Monitored, never Healthy): '.
                $unmonitored.'. Making any of them real requires a new external monitoring provider, which needs '.
                'owner approval and is not part of this change.'
            )->color('warning');
        }

        $components[] = Text::make(
            'Queue Workers measures queue BACKLOG only (pending/failed depth in the database queue tables). It does '.
            'not observe whether any worker process is alive — an idle queue and a dead worker are indistinguishable '.
            'from it. Worker liveness is reported separately on Queues & Jobs, where it is honestly marked as not '.
            'monitored.'
        )->color('gray');

        return Section::make('Monitoring Coverage')
            ->icon(Heroicon::OutlinedInformationCircle)
            ->collapsible()
            ->schema($components);
    }

    private function historySection(): Section
    {
        return Section::make('Check History')
            ->icon(Heroicon::OutlinedClock)
            ->collapsible()
            ->schema([
                Text::make(
                    'The append-only observation log behind Current Health above. One row is written per check per '.
                    'sweep, so this grows continuously — it is a forensic record, not a status display.'
                )->color('gray'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => HealthCheck::query()->whereNull('firm_id'))
            ->filters([
                SelectFilter::make('check_type')
                    ->label('Check type')
                    ->options(collect(HealthCheckType::cases())
                        ->mapWithKeys(fn (HealthCheckType $type): array => [$type->value => Str::headline($type->value)])
                        ->all()),
                SelectFilter::make('status')
                    ->options(collect(HealthCheckStatus::cases())
                        ->mapWithKeys(fn (HealthCheckStatus $status): array => [$status->value => $status->label()])
                        ->all()),
            ])
            ->columns([
                TextColumn::make('check_type')
                    ->label('Check type')
                    ->formatStateUsing(fn (HealthCheckType $state): string => Str::headline($state->value))
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (HealthCheckStatus $state): string => $state->label())
                    ->color(fn (HealthCheckStatus $state): string => $state->color())
                    ->sortable(),
                TextColumn::make('monitoring_type')
                    ->label('Monitoring type')
                    ->state(fn (HealthCheck $record): string => $this->recordedMonitoringType($record)->label())
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('detail')->label('Detail')->wrap()->placeholder('Not recorded'),
                TextColumn::make('checked_at')->label('Checked at')->dateTime()->sortable(),
                TextColumn::make('id')->label('#')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->emptyStateHeading('No health checks recorded yet')
            ->emptyStateDescription('Run health checks now, or wait for the scheduled sweep to populate this log.')
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }

    /**
     * The monitoring type recorded ON the observation itself. History
     * rows written before monitoring type was persisted have no such
     * value; those report Not Monitored rather than borrowing today's
     * registry answer, because what is monitored now says nothing
     * about what was monitored then.
     */
    private function recordedMonitoringType(HealthCheck $record): HealthCheckMonitoringType
    {
        $persisted = $record->metadata_json['monitoring_type'] ?? null;

        return is_string($persisted)
            ? (HealthCheckMonitoringType::tryFrom($persisted) ?? HealthCheckMonitoringType::NotMonitored)
            : HealthCheckMonitoringType::NotMonitored;
    }
}
