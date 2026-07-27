<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Actions\Platform\DeleteFailedJobAction;
use App\Filament\Actions\Platform\RetryFailedJobAction;
use App\Models\FailedJob;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\QueueHealthService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
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

    protected static ?int $navigationSort = 81;

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
            EmbeddedTable::make(),
        ]);
    }

    private function summarySection(): Section
    {
        $queueHealth = app(QueueHealthService::class);
        $pendingByQueue = $queueHealth->pendingJobsCountByQueue();
        $failedCount = $queueHealth->failedJobsCount();
        $oldestAge = $queueHealth->oldestPendingJobAgeSeconds();

        $pendingSummary = empty($pendingByQueue)
            ? 'No pending jobs on any queue.'
            : collect($pendingByQueue)->map(fn (int $count, string $queue): string => "{$queue}: {$count}")->implode(', ');

        return Section::make('Summary')
            ->schema([
                Grid::make(3)->schema([
                    Text::make("Pending jobs by queue: {$pendingSummary}"),
                    Text::make("Total failed jobs: {$failedCount}"),
                    Text::make('Oldest pending job age: '.($oldestAge === null ? '—' : "{$oldestAge}s")),
                ]),
            ]);
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
                TextColumn::make('connection')->label('Connection')->sortable(),
                TextColumn::make('exception')
                    ->label('Exception summary')
                    ->formatStateUsing(fn (?string $state): string => app(QueueHealthService::class)->exceptionSummary($state))
                    ->wrap(),
                TextColumn::make('failed_at')->label('Failed at')->dateTime()->sortable(),
                TextColumn::make('id')->label('#')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                RetryFailedJobAction::make(),
                DeleteFailedJobAction::make(),
            ])
            ->emptyStateHeading('No failed jobs')
            ->emptyStateDescription('Nothing needs retrying or clearing right now.')
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }
}
