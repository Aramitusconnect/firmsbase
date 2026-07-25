<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Pages\PlatformSecurityDashboardPage;
use App\Filament\Pages\PlatformTenantIsolationPage;
use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * PlatformSecurityOverviewWidget — Phase 1 FirmsVault Admin Control
 * Center, Executive Dashboard. Compact tenant-isolation status and
 * "latest security verification" timestamp, both derived from the SAME
 * RlsSecurityReportService::cachedGenerate() cache PlatformTenantIsolationPage
 * already warms/reads (5-minute TTL, keyed identically) — never a
 * second independent report generation.
 *
 * Gate: canAccessSecurityLogs() — the same gate both
 * PlatformSecurityDashboardPage and PlatformTenantIsolationPage already
 * use.
 *
 * Empty state: if the coverage mapping service reports zero tracked
 * tables (not expected, not assumed away), every count renders as 0
 * rather than being hidden.
 */
class PlatformSecurityOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -4;

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
        return 'Tenant Isolation & Security';
    }

    protected function getDescription(): ?string
    {
        $section = $this->snapshot['security'] ?? null;

        if ($section === null || ($section['authorized'] ?? false) !== true) {
            return null;
        }

        $verifiedAt = $section['latest_verification_at'] ?? null;

        return 'Last verification: '.($verifiedAt ?? '—')
            .' — see '.PlatformTenantIsolationPage::getUrl().' and '.PlatformSecurityDashboardPage::getUrl().'.';
    }

    protected function getStats(): array
    {
        $section = $this->snapshot['security'] ?? null;

        if ($section === null || ($section['authorized'] ?? false) !== true) {
            return [];
        }

        $isolation = $section['tenant_isolation'] ?? [];
        $bypassRls = $section['runtime_role_has_bypass_rls'] ?? null;

        return [
            Stat::make('FORCE RLS active', (string) ($isolation['forced'] ?? 0))
                ->icon(Heroicon::OutlinedLockClosed)
                ->color('success'),
            Stat::make('Uncovered tables', (string) ($isolation['uncovered'] ?? 0))
                ->color(($isolation['uncovered'] ?? 0) > 0 ? 'warning' : 'success'),
            Stat::make('Runtime role BYPASSRLS', match ($bypassRls) {
                true => 'YES',
                false => 'no',
                null => '<unavailable>',
            })
                ->color($bypassRls === true ? 'danger' : 'success'),
        ];
    }
}
