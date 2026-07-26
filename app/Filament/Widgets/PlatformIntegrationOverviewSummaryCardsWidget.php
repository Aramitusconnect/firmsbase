<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * PlatformIntegrationOverviewSummaryCardsWidget — Phase 2 of the
 * FirmsVault Platform Admin Control Center mission ("Integration
 * Operations Center"), Integration Overview UI-building pass. The
 * "dashboard summary cards" required on PlatformIntegrationOverviewPage.
 *
 * Deliberately distinct from the pre-existing
 * App\Filament\Widgets\PlatformIntegrationsHealthWidget: that widget is
 * wired into the Executive Dashboard's `$snapshot`-injection mechanism
 * (App\Services\PlatformExecutiveDashboardService::snapshot(), fed via
 * App\Filament\Pages\Dashboard::getWidgetData()) and lives on a
 * different page entirely. This widget is registered directly on
 * PlatformIntegrationOverviewPage via getHeaderWidgets() and computes
 * its own numbers with a single bounded SQL aggregate query — never by
 * iterating every row of `integration_platform_overview_summaries` in
 * PHP (which IntegrationPlatformOversightReadService::overviewSummaries()
 * would otherwise require, and which the mission's own "reuse, do not
 * recompute" instruction does not require going through the read
 * service for — one COUNT/SUM query over the real, no-RLS summary table
 * IS "reusing the real summary data," not recomputing a live cross-firm
 * value from the underlying FORCE-RLS tenant tables).
 *
 * Gate: canAccessIntegrationOversight() — the same gate the page itself
 * uses.
 *
 * Empty state: zero rows in the summary table yields every stat at "0"
 * and a "No data yet" description, never a fabricated timestamp/count.
 */
class PlatformIntegrationOverviewSummaryCardsWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessIntegrationOversight($admin)->allowed;
    }

    protected function getStats(): array
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return [];
        }

        if (! app(PlatformStaffAccessPolicyService::class)->canAccessIntegrationOversight($admin)->allowed) {
            return [];
        }

        $aggregate = DB::table('integration_platform_overview_summaries')
            ->selectRaw('count(*) as firm_count')
            ->selectRaw('coalesce(sum(connection_count_active), 0) as connected')
            ->selectRaw('coalesce(sum(connection_count_disconnected), 0) as disconnected')
            ->selectRaw('coalesce(sum(connection_count_other), 0) as attention_required')
            ->selectRaw("count(*) filter (where health_summary_state in ('degraded', 'action_required', 'unavailable')) as unhealthy_firm_count")
            ->selectRaw('coalesce(sum(failed_permanent_sync_item_count), 0) as failed_records')
            ->selectRaw('coalesce(sum(dead_lettered_outbox_event_count), 0) as dead_letter_queue')
            ->selectRaw('coalesce(sum(open_conflict_count), 0) as open_conflicts')
            ->selectRaw('count(*) filter (where entitlement_enabled) as entitled_firm_count')
            ->first();

        $firmCount = (int) ($aggregate->firm_count ?? 0);
        $unhealthyFirmCount = (int) ($aggregate->unhealthy_firm_count ?? 0);
        $failedRecords = (int) ($aggregate->failed_records ?? 0);
        $deadLetterQueue = (int) ($aggregate->dead_letter_queue ?? 0);
        $openConflicts = (int) ($aggregate->open_conflicts ?? 0);

        return [
            Stat::make('Connected', (string) ($aggregate->connected ?? 0))
                ->description($firmCount === 0 ? 'No data yet' : "Across {$firmCount} firm summaries")
                ->icon(Heroicon::OutlinedLink)
                ->color('success'),
            Stat::make('Disconnected', (string) ($aggregate->disconnected ?? 0))
                ->icon(Heroicon::OutlinedLinkSlash)
                ->color('gray'),
            Stat::make('Attention Required', (string) ($aggregate->attention_required ?? 0))
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color(($aggregate->attention_required ?? 0) > 0 ? 'warning' : 'success'),
            Stat::make('Firms with Degraded Health', (string) $unhealthyFirmCount)
                ->color($unhealthyFirmCount > 0 ? 'danger' : 'success'),
            Stat::make('Failed Records', (string) $failedRecords)
                ->color($failedRecords > 0 ? 'danger' : 'success'),
            Stat::make('Dead-Letter Queue', (string) $deadLetterQueue)
                ->color($deadLetterQueue > 0 ? 'danger' : 'success'),
            Stat::make('Open Conflicts', (string) $openConflicts)
                ->color($openConflicts > 0 ? 'warning' : 'success'),
            Stat::make('Integration Access Enabled', (string) ($aggregate->entitled_firm_count ?? 0))
                ->description($firmCount === 0 ? 'No data yet' : "of {$firmCount} firms"),
        ];
    }
}
