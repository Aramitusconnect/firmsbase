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
 * PlatformAdministratorsOverviewWidget — Phase 1 FirmsVault Admin
 * Control Center, Executive Dashboard. Active PlatformAdmins count and
 * PlatformAdmins without confirmed MFA — read straight off
 * `platform_admins` (non-tenant, no RLS).
 *
 * Gate: canAccessSecurityLogs() — matches
 * PlatformSecurityDashboardPage's own gate for this identical roster/MFA
 * data (that page's "PlatformAdmins Without Confirmed MFA" section uses
 * the same check at the page level).
 *
 * Empty state: 0 for both counts if there are somehow no PlatformAdmins
 * at all (not expected, not assumed away). The "without confirmed MFA"
 * stat is colored danger whenever it is greater than 0 (every such
 * admin is a real, actionable enrollment gap) and success at exactly 0.
 */
class PlatformAdministratorsOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -8;

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

        return app(PlatformStaffAccessPolicyService::class)->canAccessSecurityLogs($admin)->allowed;
    }

    protected function getHeading(): ?string
    {
        return 'Platform Administrators';
    }

    protected function getStats(): array
    {
        $section = $this->snapshot['platform_admins'] ?? null;

        if ($section === null || ($section['authorized'] ?? false) !== true) {
            return [];
        }

        $withoutMfa = (int) ($section['without_confirmed_mfa_count'] ?? 0);

        return [
            Stat::make('Active administrators', (string) ($section['active_count'] ?? 0))
                ->icon(Heroicon::OutlinedShieldCheck),
            Stat::make('Without confirmed MFA', (string) $withoutMfa)
                ->icon(Heroicon::OutlinedShieldExclamation)
                ->color($withoutMfa > 0 ? 'danger' : 'success'),
        ];
    }
}
