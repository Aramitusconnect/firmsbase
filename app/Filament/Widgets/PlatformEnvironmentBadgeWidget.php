<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * PlatformEnvironmentBadgeWidget — Phase 1 FirmsVault Admin Control
 * Center, Executive Dashboard. The environment badge (STAGING/PRODUCTION/
 * LOCAL/TESTING) required by the mission brief.
 *
 * Placement choice (documented, not silently assumed): this is a
 * DASHBOARD-ONLY widget, not a panel-wide render hook
 * (Filament\View\PanelsRenderHook — e.g. TOPBAR_END, injected via a
 * ->renderHook() call in AdminPanelProvider, which WOULD appear on
 * every page in this panel). Filament 4 does support that broader
 * extension point, but the mission only requires the badge be "somewhere
 * sensibly visible on this page" (the Executive Dashboard) — scoping it
 * to a first-widget-in-the-grid placement here satisfies that
 * requirement without touching every other page's chrome (FirmResource,
 * PlatformAdministratorResource, the Security Dashboard, etc.), which
 * would be a materially larger, unrequested layout change. If a future
 * requirement needs the badge on every page, a
 * PanelsRenderHook::TOPBAR_END registration reusing this same
 * environment-color logic is the natural next step.
 *
 * Reads only App\Services\PlatformExecutiveDashboardService's
 * `environment` snapshot section, injected via
 * App\Filament\Pages\Dashboard::getWidgetData() — never calls
 * app()->environment() itself, so every widget on this page agrees on
 * exactly one environment read per page load.
 */
class PlatformEnvironmentBadgeWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -20;

    /**
     * Every Filament Widget defaults to lazy-loading (an extra Livewire
     * AJAX round-trip after the initial page load) — appropriate for a
     * widget that runs its OWN expensive query on mount. Every Executive
     * Dashboard widget instead reads from `$snapshot`, computed exactly
     * ONCE by the Dashboard page BEFORE any widget mounts (see that
     * page's own docblock) — there is no per-widget query to defer, so
     * lazy-loading here would only add N unnecessary round-trips with no
     * benefit. Disabled on every widget in this directory for the same
     * reason.
     */
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    /**
     * @var array<string, mixed>
     */
    public array $snapshot = [];

    protected function getStats(): array
    {
        $environment = $this->snapshot['environment'] ?? null;

        if ($environment === null) {
            return [];
        }

        $name = strtoupper((string) $environment['name']);

        $color = match (true) {
            $environment['is_production'] => 'danger',
            $environment['is_staging'] => 'warning',
            $environment['is_testing'] => 'gray',
            default => 'info',
        };

        $description = $environment['is_production']
            ? 'Production — every action here is real.'
            : 'Non-production environment.';

        return [
            Stat::make('Environment', $name)
                ->description($description)
                ->color($color),
        ];
    }
}
