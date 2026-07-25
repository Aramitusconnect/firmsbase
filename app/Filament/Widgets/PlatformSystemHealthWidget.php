<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * PlatformSystemHealthWidget — Phase 1 FirmsVault Admin Control Center,
 * Executive Dashboard. Failed jobs, pending queued jobs (when
 * observable), deployed git commit, and scheduler status.
 *
 * Ungated (canView() left at the Widget base class's default `true`):
 * none of this is tenant, roster, or security-event content — it is
 * process/deployment-level operational information appropriate for
 * every active PlatformAdmin who can reach the dashboard at all.
 *
 * Every value below is read straight from
 * PlatformExecutiveDashboardService::snapshot()'s `system` section —
 * see that method's own docblock for exactly where each number comes
 * from (failed_jobs/jobs tables, RlsSecurityReportService's cached git
 * commit). "Scheduler status" is honestly labeled unavailable rather
 * than fabricated — see that section's own docblock for why
 * deployment_health_checks does NOT answer this question.
 */
class PlatformSystemHealthWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -2;

    /**
     * See PlatformEnvironmentBadgeWidget's own docblock — every
     * Executive Dashboard widget reads from a pre-computed `$snapshot`,
     * so lazy-loading (Filament's default) buys nothing here.
     */
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    /**
     * @var array<string, mixed>
     */
    public array $snapshot = [];

    protected function getHeading(): ?string
    {
        return 'Platform & Infrastructure';
    }

    protected function getDescription(): ?string
    {
        $system = $this->snapshot['system'] ?? null;

        if ($system === null) {
            return null;
        }

        $commit = $system['git_commit'] ?? null;

        return 'Deployed commit: '.($commit ?? '<unavailable>');
    }

    protected function getStats(): array
    {
        $system = $this->snapshot['system'] ?? null;

        if ($system === null) {
            return [];
        }

        $failedJobs = (int) ($system['failed_jobs_count'] ?? 0);

        $queuePendingLabel = ($system['queue_pending_jobs_observable'] ?? false)
            ? (string) ($system['queue_pending_jobs'] ?? 0)
            : 'not observable';

        return [
            Stat::make('Queue ('.($system['queue_connection'] ?? '—').')', $queuePendingLabel)
                ->description('Pending jobs')
                ->icon(Heroicon::OutlinedQueueList),
            Stat::make('Failed jobs', (string) $failedJobs)
                ->icon(Heroicon::OutlinedExclamationCircle)
                ->color($failedJobs > 0 ? 'danger' : 'success'),
            Stat::make('Scheduler', strtoupper((string) ($system['scheduler_status'] ?? 'unavailable')))
                ->description($system['scheduler_status_reason'] ?? null)
                ->color('gray'),
        ];
    }
}
