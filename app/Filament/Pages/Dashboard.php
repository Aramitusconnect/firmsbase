<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\PlatformAdministratorsOverviewWidget;
use App\Filament\Widgets\PlatformEnvironmentBadgeWidget;
use App\Filament\Widgets\PlatformFirmsOverviewWidget;
use App\Filament\Widgets\PlatformIntegrationsHealthWidget;
use App\Filament\Widgets\PlatformRecentPrivilegedActivityWidget;
use App\Filament\Widgets\PlatformSecurityOverviewWidget;
use App\Filament\Widgets\PlatformSystemHealthWidget;
use App\Models\PlatformAdmin;
use App\Services\PlatformExecutiveDashboardService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * Dashboard — Phase 1 FirmsVault Admin Control Center, final scope
 * item: the Executive Dashboard, replacing Filament's default dashboard
 * content. Overrides Filament\Pages\Dashboard rather than registering a
 * brand-new Page class at a different route — this IS Filament 4's own
 * documented convention for customizing the panel's landing page: the
 * parent class already hardcodes `$routePath = '/'` and is registered
 * explicitly in AdminPanelProvider::pages() (this checkpoint replaces
 * that entry with this subclass, see that provider's own docblock).
 *
 * Widget data flow (no widget ever queries the database directly):
 * getWidgets() below returns an explicit, ordered list of this
 * checkpoint's own Widget classes (never Filament::getWidgets(), the
 * parent class's default — that pool also contains whatever the panel's
 * ->widgets()/->discoverWidgets() registers, which this page does not
 * want to render blindly). getWidgetData() overrides the parent's
 * (empty-array) default to inject ONE PlatformExecutiveDashboardService::
 * snapshot() array, computed exactly once per page load (memoized on
 * $snapshot below) and merged into every mounted widget's public
 * properties by Filament's own Livewire::make() wiring in
 * Page::getWidgetsSchemaComponents() (vendor/filament/filament/src/Pages/Page.php)
 * — each widget below declares a matching `public array $snapshot = []`
 * property and reads its own section from it, never re-querying.
 *
 * Gate: canAccess() is left at the parent's default (true for every
 * authenticated panel user — see Filament\Pages\Concerns\
 * CanAuthorizeAccess's own docblock) — a deliberate choice, not an
 * oversight: this is the landing page every authenticated,
 * MFA-verified PlatformAdmin sees immediately after login (per the
 * panel's own authMiddleware() — Authenticate then
 * EnsurePlatformAdminMfaIsEnrolledAndVerified — and canAccessPanel()'s
 * is_active check), so it must not be role-restricted the way every
 * other Platform*Page in this directory is. Individual sensitive
 * sections are instead gated per-widget (see each Widget class's own
 * canView()), exactly per this checkpoint's brief.
 */
class Dashboard extends \Filament\Pages\Dashboard
{
    protected static ?string $title = 'Executive Dashboard';

    protected static ?string $navigationLabel = 'Dashboard';

    /**
     * Memoized for the lifetime of this Livewire component instance —
     * getWidgetData() is called once per mounted widget (one call per
     * widget) by Page::getWidgetsSchemaComponents(), so without this
     * memo the (cached, but still non-free) snapshot() call would run
     * once per widget instead of once per page load.
     *
     * @var array<string, mixed>|null
     */
    private ?array $snapshot = null;

    public function getColumns(): int|array
    {
        return [
            'default' => 1,
            'sm' => 2,
            'lg' => 3,
        ];
    }

    /**
     * @return array<class-string<Widget>>
     */
    public function getWidgets(): array
    {
        return [
            PlatformEnvironmentBadgeWidget::class,
            PlatformFirmsOverviewWidget::class,
            PlatformAdministratorsOverviewWidget::class,
            PlatformIntegrationsHealthWidget::class,
            PlatformSecurityOverviewWidget::class,
            PlatformSystemHealthWidget::class,
            PlatformRecentPrivilegedActivityWidget::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        return [
            'snapshot' => $this->snapshot(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        if ($this->snapshot !== null) {
            return $this->snapshot;
        }

        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            // Cannot happen on a real request (authMiddleware guarantees
            // an authenticated PlatformAdmin before this page ever
            // mounts) — defensive fallback only, mirrors the
            // "not signed in" guard every sibling Platform*Page already
            // uses in its own closures.
            return $this->snapshot = [];
        }

        return $this->snapshot = app(PlatformExecutiveDashboardService::class)->snapshot($admin);
    }
}
