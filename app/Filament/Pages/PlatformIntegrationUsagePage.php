<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\PlatformAdmin;
use App\Services\IntegrationPlatformOversightReadService;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * PlatformIntegrationUsagePage — Phase 2 (FirmsVault Platform Admin
 * Control Center, "Integration Operations Center").
 *
 * CORRECTED during Checkpoint 6's cross-provider ops review: an earlier
 * version of this page disclosed that `integration_usage_records` had
 * zero real writers and rendered an honest "not yet available" notice
 * instead of fabricating numbers — a correct call at the time. That
 * disclosure has since gone stale: `ProviderRequestExecutor::send()`
 * (the shared outbound HTTP path every provider — Microsoft 365, Google
 * Workspace, Plaid — routes through) now calls
 * `IntegrationUsageRecorderService::recordOnce()` for every provider
 * call, so real rows exist today. This page now surfaces a genuine
 * summary via
 * `IntegrationPlatformOversightReadService::usageRecordSummaryAcrossFirms()`
 * (see that method's own docblock for the per-firm-loop, RLS-safe read
 * pattern) instead of the stale banner.
 *
 * The other signal this page surfaces — clearly labeled "Sync Volume",
 * never "Usage", because that is genuinely what it is — is a small
 * aggregate rollup computed from `integration_platform_overview_summaries`
 * via the existing, unmodified
 * IntegrationPlatformOversightReadService::overviewSummaries() (the
 * SAME no-RLS, pre-computed, bounded snapshot table
 * PlatformIntegrationOverviewPage's own per-firm list already reads —
 * no new read path, no new cross-firm query, no new table). This is a
 * SNAPSHOT rollup (sum of a handful of already-computed per-firm
 * counters), not a live query and not a time series — `computed_at`
 * staleness applies exactly as it does on the Overview page.
 */
class PlatformIntegrationUsagePage extends Page
{
    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Integration Usage';

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 24;

    protected static ?string $title = 'Integration Usage';

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
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return $schema->components([
                Text::make('You are not signed in as a platform admin.')->color('danger'),
            ]);
        }

        return $schema->components([
            $this->usageMeteringSection($admin),
            $this->syncVolumeSnapshotSection($admin),
        ]);
    }

    private function usageMeteringSection(PlatformAdmin $admin): Section
    {
        return Section::make('Usage Metering')
            ->icon(Heroicon::OutlinedChartBar)
            ->description('A cross-firm rollup of recorded integration usage — call volume by provider, sanitized quantity/unit aggregates only, never a cost figure or raw provider payload.')
            ->schema([
                UnorderedList::make(function () use ($admin): array {
                    try {
                        $summary = app(IntegrationPlatformOversightReadService::class)->usageRecordSummaryAcrossFirms($admin);
                    } catch (RuntimeException $e) {
                        return [$e->getMessage()];
                    }

                    if ($summary['total_records'] === 0) {
                        return ['No usage has been recorded yet.'];
                    }

                    $lines = [
                        sprintf('Total recorded usage units: %d', $summary['total_records']),
                        sprintf('Firms with recorded usage: %d', $summary['firms_with_usage']),
                    ];

                    foreach ($summary['by_provider'] as $providerKey => $quantity) {
                        $lines[] = sprintf('%s: %d', $providerKey, $quantity);
                    }

                    if ($summary['earliest_occurred_at'] !== null && $summary['latest_occurred_at'] !== null) {
                        $lines[] = sprintf('Recorded between %s and %s.', $summary['earliest_occurred_at'], $summary['latest_occurred_at']);
                    }

                    return $lines;
                }),
            ]);
    }

    /**
     * Clearly-labeled "Sync Volume" — never "Usage" — snapshot. See this
     * class's own docblock for why this is a legitimate, honest proxy
     * rather than fabricated usage data.
     */
    private function syncVolumeSnapshotSection(PlatformAdmin $admin): Section
    {
        return Section::make('Sync Volume Snapshot (not usage)')
            ->icon(Heroicon::OutlinedArrowPath)
            ->description('A snapshot rollup of connection counts and recent sync activity across every firm, from the same pre-computed summary table the Integration Oversight page uses. This is sync activity, not a usage/billing metric.')
            ->collapsible()
            ->schema([
                UnorderedList::make(function () use ($admin): array {
                    try {
                        $summaries = app(IntegrationPlatformOversightReadService::class)->overviewSummaries($admin);
                    } catch (RuntimeException $e) {
                        return [$e->getMessage()];
                    }

                    if ($summaries->isEmpty()) {
                        return ['No firms have any integration activity recorded yet.'];
                    }

                    $firmsWithSyncActivity = $summaries->filter(fn (array $row): bool => filled($row['last_sync_at'] ?? null))->count();

                    return [
                        sprintf('Firms with integration connections: %d', $summaries->count()),
                        sprintf('Firms with at least one recorded sync: %d', $firmsWithSyncActivity),
                        sprintf('Total active connections: %d', $summaries->sum('connection_count_active')),
                        sprintf('Total disconnected connections: %d', $summaries->sum('connection_count_disconnected')),
                        sprintf('Total failed-permanent sync items (current snapshot): %d', $summaries->sum('failed_permanent_sync_item_count')),
                        sprintf('Total dead-lettered outbox events (current snapshot): %d', $summaries->sum('dead_lettered_outbox_event_count')),
                        sprintf('Total open conflicts (current snapshot): %d', $summaries->sum('open_conflict_count')),
                    ];
                }),
            ]);
    }
}
