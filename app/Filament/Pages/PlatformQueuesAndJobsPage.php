<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Actions\Platform\DeleteFailedJobAction;
use App\Filament\Actions\Platform\RetryFailedJobAction;
use App\Models\FailedJob;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\QueueHealthService;
use App\Services\QueueObservabilityService;
use App\ValueObjects\QueueObservation;
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

/**
 * PlatformQueuesAndJobsPage — Phase 4 (FirmsVault Platform Admin
 * Control Center, "Operations"). The drill-down page the existing
 * Phase 1 Executive Dashboard tile (PlatformSystemHealthWidget) is a
 * summary of — see phase4-architecture-map-operations-governance.md
 * §A.2's own framing. Two parts: (1) a per-queue pending-count summary
 * plus total failed count/oldest pending age via QueueHealthService,
 * (2) a browsable, paginated `failed_jobs` table (via the read-only
 * FailedJob Eloquent model — no new table) with Retry/Delete row
 * actions.
 *
 * `jobs`/`failed_jobs` carry no RLS at all (System/global — see
 * QueueHealthService's own docblock), so no tenant-context wrapping
 * is needed anywhere on this page.
 *
 * Exception summaries are deliberately truncated to a single line via
 * QueueHealthService::exceptionSummary() — the raw serialized payload/
 * full stack trace is never rendered, since either could embed
 * sensitive job/request data.
 */
class PlatformQueuesAndJobsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $navigationLabel = 'Queues & Jobs';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 82;

    protected static ?string $title = 'Queues & Jobs';

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
            $this->summarySection(),
            $this->workerEvidenceSection(),
            $this->attentionSection(),
            EmbeddedTable::make(),
        ]);
    }

    /**
     * Per-queue backlog, measured. One line per queue with any
     * observable activity, showing pending / in-flight / delayed /
     * failed and the age of the oldest waiting work.
     */
    private function summarySection(): Section
    {
        $observations = app(QueueObservabilityService::class)->observeAll();

        if ($observations === []) {
            return Section::make('Queue Backlog')
                ->icon(Heroicon::OutlinedQueueList)
                ->schema([
                    Text::make('No queue currently has any pending, in-flight, delayed, or failed jobs.'),
                    Text::make(
                        'Queues are created implicitly when work is pushed to them, so the database queue driver has '.
                        'no registry of queues that "should" exist — an absent queue cannot be distinguished from a '.
                        'queue that has simply never been used.'
                    )->color('gray'),
                ]);
        }

        $lines = array_map(
            fn (QueueObservation $observation): Text => Text::make(sprintf(
                '%s — %d pending, %d in flight, %d delayed, %d failed · oldest pending: %s · oldest failed: %s',
                $observation->queue,
                $observation->pending,
                $observation->reserved,
                $observation->delayed,
                $observation->failed,
                $observation->oldestPendingAgeSeconds === null ? 'none waiting' : $observation->oldestPendingAgeSeconds.'s',
                $observation->oldestFailedAgeSeconds === null ? 'none' : $observation->oldestFailedAgeSeconds.'s',
            ))->color($observation->hasFailures() ? 'warning' : null),
            $observations,
        );

        return Section::make('Queue Backlog')
            ->icon(Heroicon::OutlinedQueueList)
            ->description('Measured from the database queue tables. Backlog only — see Worker Liveness below.')
            ->schema($lines);
    }

    /**
     * The section that exists specifically to stop the most common
     * misreading of the section above. Queue depth says nothing about
     * whether a worker is running; this platform has no worker
     * heartbeat, so it says so rather than inferring.
     */
    private function workerEvidenceSection(): Section
    {
        $service = app(QueueObservabilityService::class);
        $worker = $service->workerEvidence();
        $processed = $service->processedRecentlyEvidence();

        return Section::make('Worker Liveness — Not Monitored')
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->schema([
                Text::make('Workers expected: Not Monitored · Workers healthy: Not Monitored · Last worker heartbeat: Never Observed')
                    ->color('warning'),
                Text::make($worker['reason'])->color('gray'),
                Text::make('Processed recently: Not Available. '.$processed['reason'])->color('gray'),
            ]);
    }

    /**
     * Deterministic backlog rules, each showing its own source,
     * threshold and observed value so the verdict can be checked
     * rather than trusted.
     */
    private function attentionSection(): Section
    {
        $signals = app(QueueObservabilityService::class)->attentionSignals();

        if ($signals === []) {
            return Section::make('Queue Attention')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->collapsible()
                ->schema([
                    Text::make('No queue backlog or failure threshold is currently exceeded.'),
                    Text::make(
                        'This statement covers backlog and failures only. It is not a statement about worker '.
                        'liveness, which is not monitored.'
                    )->color('gray'),
                ]);
        }

        return Section::make('Queue Attention ('.count($signals).')')
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->schema(array_map(
                fn (array $signal): Text => Text::make(sprintf(
                    '%s — queue "%s" · observed %s against a threshold of %s (source: %s). %s',
                    $signal['signal'],
                    $signal['queue'],
                    $signal['observed'],
                    $signal['threshold'],
                    $signal['source'],
                    $signal['why'],
                ))->color('warning'),
                $signals,
            ));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => FailedJob::query())
            ->filters([
                SelectFilter::make('queue')
                    ->options(fn (): array => FailedJob::query()->distinct()->orderBy('queue')->pluck('queue', 'queue')->all()),
                SelectFilter::make('connection')
                    ->options(fn (): array => FailedJob::query()->distinct()->orderBy('connection')->pluck('connection', 'connection')->all()),
            ])
            ->columns([
                TextColumn::make('queue')->label('Queue')->sortable(),
                TextColumn::make('job_class')
                    ->label('Job')
                    ->state(fn (FailedJob $record): string => $this->jobDisplayName($record))
                    ->description(fn (FailedJob $record): string => $record->connection)
                    ->wrap(),
                TextColumn::make('exception')
                    ->label('Failure summary')
                    ->formatStateUsing(fn (?string $state): string => app(QueueHealthService::class)->exceptionSummary($state))
                    ->tooltip('First line only. The full stack trace and the serialized payload are never rendered — either can embed client, matter, or credential data.')
                    ->wrap(),
                TextColumn::make('failed_at')
                    ->label('Failed at')
                    ->dateTime()
                    ->description(fn (FailedJob $record): string => $record->failed_at?->diffForHumans() ?? 'Unknown')
                    ->sortable(),
                TextColumn::make('uuid')
                    ->label('Correlation ID')
                    ->fontFamily('mono')
                    ->limit(13)
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('id')->label('#')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                RetryFailedJobAction::make(),
                DeleteFailedJobAction::make(),
            ])
            ->emptyStateHeading('No failed jobs')
            // Scoped deliberately to what was actually queried. This
            // says nothing about backlog (see Queue Attention above)
            // and nothing about workers (not monitored) — a broader
            // reassurance here would cover surfaces this table never
            // looked at.
            ->emptyStateDescription('The failed_jobs table is empty.')
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }

    /**
     * The job's class name, taken from the queue payload's
     * `displayName` — the same field Laravel itself uses to describe a
     * job. Only this one field is read: the rest of the payload holds
     * the serialized job, which routinely contains client, matter, and
     * credential data and is never rendered.
     */
    private function jobDisplayName(FailedJob $record): string
    {
        $payload = json_decode((string) $record->payload, true);

        $displayName = is_array($payload) ? ($payload['displayName'] ?? null) : null;

        return is_string($displayName) && $displayName !== ''
            ? $displayName
            : 'Unknown job class';
    }
}
