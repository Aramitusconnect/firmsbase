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
 * PlatformFirmsOverviewWidget — Phase 1 FirmsVault Admin Control Center,
 * Executive Dashboard. Total firms, firms by FirmActivationStatus (the
 * real 3 cases only — Draft/Onboarding/Activated; there is no
 * Suspended/Trial concept anywhere in this codebase, confirmed against
 * that enum's own docblock — see FirmResource's identical finding), and
 * total firm users.
 *
 * Gate: canAccessPlatformAdministration() — the same check
 * FirmResource/FirmUserResource already use for this exact data. Never
 * queries the database itself — every number below comes from
 * App\Services\PlatformExecutiveDashboardService::snapshot()'s `firms`
 * section, injected via the Dashboard page's getWidgetData().
 *
 * Empty state: zero firms renders every stat at "0" (Filament's Stat
 * component has no special empty-state affordance beyond the value
 * itself — "0" IS the honest, correct rendering, not a placeholder to
 * suppress).
 */
class PlatformFirmsOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -10;

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

        return app(PlatformStaffAccessPolicyService::class)->canAccessPlatformAdministration($admin)->allowed;
    }

    protected function getHeading(): ?string
    {
        return 'Firms';
    }

    protected function getStats(): array
    {
        $firms = $this->snapshot['firms'] ?? null;

        if ($firms === null || ($firms['authorized'] ?? false) !== true) {
            return [];
        }

        $byStatus = $firms['by_status'] ?? [];

        return [
            Stat::make('Total firms', (string) ($firms['total'] ?? 0))
                ->icon(Heroicon::OutlinedBuildingOffice2),
            Stat::make('Draft', (string) ($byStatus['draft'] ?? 0))
                ->color('gray'),
            Stat::make('Onboarding', (string) ($byStatus['onboarding'] ?? 0))
                ->color('warning'),
            Stat::make('Activated', (string) ($byStatus['activated'] ?? 0))
                ->color('success'),
            Stat::make('Total firm users', (string) ($firms['total_firm_users'] ?? 0))
                ->icon(Heroicon::OutlinedUsers),
        ];
    }
}
