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
 * HONESTY-OVER-COMPLETENESS DISCLOSURE (this is the whole point of this
 * page's design, read before changing it): the architecture
 * investigation found — and this was independently re-verified directly
 * against the current source, not trusted blindly — that
 * `integration_usage_records` has exactly ONE would-be writer,
 * `App\Integrations\Services\IntegrationUsageRecorderService::recordOnce()`,
 * and that method has ZERO real call sites anywhere in this codebase
 * outside its own file and doc-comment references (confirmed by a
 * direct grep of app/ for `IntegrationUsageRecorderService`). No
 * usage-metering system is actually wired up. This page does NOT
 * fabricate usage numbers or build a chart against data that does not
 * exist — its primary content is an honest, clearly-labeled
 * not-yet-available notice.
 *
 * The one legitimate, already-existing, already-reviewed signal this
 * page DOES surface — clearly labeled "Sync Volume", never "Usage",
 * because that is genuinely what it is — is a small aggregate rollup
 * computed from `integration_platform_overview_summaries` via the
 * existing, unmodified
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
            $this->usageNotAvailableSection(),
            $this->syncVolumeSnapshotSection($admin),
        ]);
    }

    private function usageNotAvailableSection(): Section
    {
        return Section::make('Usage Metering')
            ->icon(Heroicon::OutlinedExclamationCircle)
            ->description('No usage-metering data is available.')
            ->schema([
                Text::make(
                    'integration_usage_records exists in the schema, but its only writer '.
                    '(IntegrationUsageRecorderService::recordOnce()) has no call sites anywhere in this '.
                    'codebase — no usage-metering system is actually wired up yet. This page deliberately '.
                    'does not fabricate usage figures or chart against empty data.'
                ),
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
