<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * PlatformIntegrationsHealthWidget — Phase 1 FirmsVault Admin Control
 * Center, Executive Dashboard. Connected integrations, firms needing
 * attention, failed records, dead-lettered items, and open conflicts —
 * every number aggregated in PHP over the already-computed
 * `integration_platform_overview_summaries` rows (refreshed every 5
 * minutes by the existing RefreshIntegrationPlatformOverviewSummaryJob/
 * everyFiveMinutes() schedule) via
 * IntegrationPlatformOversightReadService::overviewSummaries(). Never a
 * new live query against any FORCE-RLS integration table.
 *
 * Gate: canAccessIntegrationOversight() — the same gate
 * PlatformIntegrationOverviewPage already uses for this exact summary
 * table.
 *
 * Empty state: zero firms with a summary row (e.g. no firm has been
 * activated/synced yet) yields every stat at 0 and a "No data yet"
 * description instead of a stale/fabricated timestamp.
 */
class PlatformIntegrationsHealthWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -6;

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

    public static function canView(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessIntegrationOversight($admin)->allowed;
    }

    protected function getHeading(): ?string
    {
        return 'Integrations';
    }

    protected function getDescription(): ?string
    {
        $section = $this->snapshot['integrations'] ?? null;

        if ($section === null || ($section['authorized'] ?? false) !== true) {
            return null;
        }

        $computedAt = $section['latest_computed_at'] ?? null;

        return $computedAt === null
            ? 'No integration summary data yet — refreshed every 5 minutes once firms have connections.'
            : "Summary data as of {$computedAt} (refreshed every 5 minutes).";
    }

    protected function getStats(): array
    {
        $section = $this->snapshot['integrations'] ?? null;

        if ($section === null || ($section['authorized'] ?? false) !== true) {
            return [];
        }

        $attentionNeeded = (int) ($section['attention_needed_firm_count'] ?? 0);

        return [
            Stat::make('Connected', (string) ($section['connected_count'] ?? 0))
                ->icon(Heroicon::OutlinedLink)
                ->color('success'),
            Stat::make('Firms needing attention', (string) $attentionNeeded)
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color($attentionNeeded > 0 ? 'warning' : 'success'),
            Stat::make('Failed records', (string) ($section['failed_permanent_sync_item_count'] ?? 0))
                ->color('danger'),
            Stat::make('Dead-lettered', (string) ($section['dead_lettered_outbox_event_count'] ?? 0))
                ->color('danger'),
            Stat::make('Open conflicts', (string) ($section['open_conflict_count'] ?? 0))
                ->color('warning'),
        ];
    }
}
