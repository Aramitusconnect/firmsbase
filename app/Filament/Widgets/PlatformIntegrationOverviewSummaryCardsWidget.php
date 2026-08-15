<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Pages\PlatformProviderHealthPage;
use App\Filament\Resources\ConflictResource;
use App\Filament\Resources\ConnectionResource;
use App\Filament\Resources\DeadLetterQueueResource;
use App\Filament\Resources\SyncFailureResource;
use App\Filament\Support\Integrations\IntegrationDisplay;
use App\Integrations\Enums\ConnectionStatus;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
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
 * Empty state (CORRECTED by Prompt 2, Integration Operations): zero rows
 * in the summary table used to yield eight stats reading "0". Every one
 * of those zeros was arithmetically true and operationally false — a SUM
 * over no rows is 0, but it means "nothing has ever been summarised",
 * not "we measured and found no failures". Eight green zeros on the
 * platform's top-level integration screen is a fabricated all-clear, so
 * the widget now renders a single explicit "No data yet" card instead
 * (§30). A measured zero still renders as "0", with a description
 * saying what was measured.
 *
 * Freshness (§22) and drill-downs (§31) were also missing: every card
 * now links to the list containing its underlying rows (pre-filtered
 * where the target resource supports it), and the Connected card
 * carries the snapshot's own max(computed_at) so an operator can see how
 * old these numbers are before acting on them.
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
            ->selectRaw('max(computed_at) as last_computed_at')
            ->first();

        $firmCount = (int) ($aggregate->firm_count ?? 0);

        // ZERO vs NEVER MEASURED (§30) — the single most important
        // distinction on this widget. Every number below is a SUM/COUNT
        // over `integration_platform_overview_summaries`. With no rows
        // in that table, those SUMs are all legitimately 0 in SQL, but
        // that 0 means "no firm has ever been summarised", NOT "we
        // checked and there are no failures". Rendering the latter is a
        // fabricated all-clear on the platform's top-level integration
        // health screen, so the whole widget switches to an explicit
        // not-yet-measured presentation instead of showing eight
        // reassuring zeros.
        if ($firmCount === 0) {
            return [
                Stat::make('Integration activity', IntegrationDisplay::NO_DATA_YET)
                    ->description('No firm integration summary has been computed yet. This is not the same as "no integration problems" — nothing has been measured.')
                    ->icon(Heroicon::OutlinedQuestionMarkCircle)
                    ->color('gray'),
            ];
        }

        $unhealthyFirmCount = (int) ($aggregate->unhealthy_firm_count ?? 0);
        $failedRecords = (int) ($aggregate->failed_records ?? 0);
        $deadLetterQueue = (int) ($aggregate->dead_letter_queue ?? 0);
        $openConflicts = (int) ($aggregate->open_conflicts ?? 0);
        $connected = (int) ($aggregate->connected ?? 0);
        $disconnected = (int) ($aggregate->disconnected ?? 0);
        $attentionRequired = (int) ($aggregate->attention_required ?? 0);

        // Freshness (§22): these snapshots are refreshed on a schedule,
        // so an operator must be able to see HOW OLD the numbers are
        // before acting on them. computed_at is NOT NULL on every row,
        // so the max is always a real measurement.
        $computedAt = $aggregate->last_computed_at ?? null;
        $freshness = $computedAt === null
            ? IntegrationDisplay::UNKNOWN
            : 'Data through '.Carbon::parse($computedAt)->diffForHumans();

        return [
            // Drill-downs (§31): each card opens the list that actually
            // contains the underlying rows, pre-filtered. These are
            // canonical Filament URLs built from the target Resource
            // itself, never hand-written paths.
            Stat::make('Connected', (string) $connected)
                ->description("Across {$firmCount} firm summaries · {$freshness}")
                ->icon(Heroicon::OutlinedLink)
                ->color('success')
                ->url(ConnectionResource::getUrl('index', [
                    'tableFilters' => ['status' => ['value' => ConnectionStatus::Active->value]],
                ])),
            Stat::make('Disconnected', (string) $disconnected)
                ->description('Connections a firm has disconnected or revoked')
                ->icon(Heroicon::OutlinedLinkSlash)
                ->color('gray')
                ->url(ConnectionResource::getUrl('index', [
                    'tableFilters' => ['status' => ['value' => ConnectionStatus::Disconnected->value]],
                ])),
            Stat::make('Attention Required', (string) $attentionRequired)
                ->description($attentionRequired > 0
                    ? 'Connections in an error or reauthorization state'
                    : 'No connection is in an error or reauthorization state')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color($attentionRequired > 0 ? 'warning' : 'success')
                ->url(ConnectionResource::getUrl('index')),
            Stat::make('Firms with Degraded Health', (string) $unhealthyFirmCount)
                ->description($unhealthyFirmCount > 0
                    ? "of {$firmCount} summarised firms"
                    : 'Every summarised firm reports healthy')
                ->icon(Heroicon::OutlinedSignal)
                ->color($unhealthyFirmCount > 0 ? 'danger' : 'success')
                ->url(PlatformProviderHealthPage::getUrl()),
            Stat::make('Failed Records', (string) $failedRecords)
                ->description($failedRecords > 0 ? 'Sync items in a failed state' : 'No failed sync records')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color($failedRecords > 0 ? 'danger' : 'success')
                ->url(SyncFailureResource::getUrl('index')),
            Stat::make('Dead-Letter Queue', (string) $deadLetterQueue)
                ->description($deadLetterQueue > 0 ? 'Events that exhausted their retries' : 'No dead-lettered events')
                ->icon(Heroicon::OutlinedInboxStack)
                ->color($deadLetterQueue > 0 ? 'danger' : 'success')
                ->url(DeadLetterQueueResource::getUrl('index')),
            Stat::make('Open Conflicts', (string) $openConflicts)
                ->description($openConflicts > 0
                    ? 'Awaiting the firm\'s own dual-approval workflow'
                    : 'No conflicts currently require monitoring')
                ->icon(Heroicon::OutlinedScale)
                ->color($openConflicts > 0 ? 'warning' : 'success')
                ->url(ConflictResource::getUrl('index')),
            Stat::make('Integration Access Enabled', (string) ($aggregate->entitled_firm_count ?? 0))
                ->description("of {$firmCount} summarised firms")
                ->icon(Heroicon::OutlinedCheckBadge),
        ];
    }
}
