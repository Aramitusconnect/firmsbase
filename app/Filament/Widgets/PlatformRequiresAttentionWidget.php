<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Pages\PlatformRolesAndPermissionsPage;
use App\Filament\Pages\PlatformTenantIsolationPage;
use App\Filament\Resources\PlatformAdministratorResource;
use App\Models\PlatformAdmin;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

/**
 * PlatformRequiresAttentionWidget — CORE SuperAdmin mission, section
 * 15. A prominent "Requires Attention" surface, positioned first among
 * the Executive Dashboard's widgets (see Dashboard::getWidgets()).
 *
 * Every signal below is derived from data PlatformExecutiveDashboardService::
 * snapshot() ALREADY computed for this exact page load (no new query,
 * no new service call) — this widget never queries the database
 * directly, matching every sibling Executive Dashboard widget's own
 * documented discipline.
 *
 * Deliberately does NOT include signals this codebase cannot actually
 * measure — no fabricated "Firm provisioning failed" (FirmActivationStatus
 * has no Failed case — see FirmResource's own docblock), no "Critical
 * security event" (SecurityEvent carries no severity column — see
 * PlatformSecurityDashboardService's own docblock), no "Locked
 * privileged account" (PlatformAdmin carries no lock/lockout column —
 * confirmed by direct source read). Only genuinely trackable signals
 * are surfaced; anything else is a disclosed gap in this mission's
 * final report, not silently invented here.
 */
class PlatformRequiresAttentionWidget extends Widget
{
    protected static ?int $sort = -10;

    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.platform-requires-attention-widget';

    protected int|string|array $columnSpan = 'full';

    /**
     * @var array<string, mixed>
     */
    public array $snapshot = [];

    /**
     * @return array<int, array{message: string, level: string, url: ?string}>
     */
    public function items(): array
    {
        $items = [];

        $platformAdmins = $this->snapshot['platform_admins'] ?? null;
        if (($platformAdmins['authorized'] ?? false) === true) {
            $withoutMfa = (int) ($platformAdmins['without_confirmed_mfa_count'] ?? 0);
            if ($withoutMfa > 0) {
                $items[] = [
                    'message' => "{$withoutMfa} platform administrator(s) have not confirmed MFA enrollment.",
                    'level' => 'warning',
                    'url' => PlatformAdministratorResource::getUrl(),
                ];
            }

            $activeSuperAdmins = (int) ($platformAdmins['active_super_admin_count'] ?? 0);
            if ($activeSuperAdmins === 1) {
                $items[] = [
                    'message' => 'Only one active SuperAdmin exists on this platform. Losing access to that account would require the out-of-band emergency recovery path.',
                    'level' => 'warning',
                    'url' => PlatformRolesAndPermissionsPage::getUrl(),
                ];
            } elseif ($activeSuperAdmins === 0) {
                $items[] = [
                    'message' => 'No active SuperAdmin currently exists on this platform.',
                    'level' => 'critical',
                    'url' => PlatformRolesAndPermissionsPage::getUrl(),
                ];
            }
        }

        $security = $this->snapshot['security'] ?? null;
        if (($security['authorized'] ?? false) === true) {
            if ($security['runtime_role_is_superuser'] === true || $security['runtime_role_has_bypass_rls'] === true) {
                $items[] = [
                    'message' => 'This application\'s database runtime role currently has elevated privileges (Superuser or BYPASSRLS). Row-level security cannot be relied on to isolate tenants for this connection.',
                    'level' => 'critical',
                    'url' => PlatformTenantIsolationPage::getUrl(),
                ];
            }

            $uncovered = (int) ($security['tenant_isolation']['uncovered'] ?? 0);
            if ($uncovered > 0) {
                $items[] = [
                    'message' => "{$uncovered} tenant-owned table(s) still lack row-level security preparation.",
                    'level' => 'critical',
                    'url' => PlatformTenantIsolationPage::getUrl(),
                ];
            }

            $verifiedAt = $security['latest_verification_at'] ?? null;
            if ($verifiedAt !== null) {
                $age = Carbon::parse($verifiedAt)->diffInHours(now());
                if ($age > 24) {
                    $items[] = [
                        'message' => "Tenant isolation was last verified {$age} hour(s) ago. Consider running verification again.",
                        'level' => 'info',
                        'url' => PlatformTenantIsolationPage::getUrl(),
                    ];
                }
            }
        }

        $integrations = $this->snapshot['integrations'] ?? null;
        if (($integrations['authorized'] ?? false) === true) {
            $attentionNeeded = (int) ($integrations['attention_needed_firm_count'] ?? 0);
            if ($attentionNeeded > 0) {
                $items[] = [
                    'message' => "{$attentionNeeded} firm(s) have degraded, action-required, or unavailable integration health.",
                    'level' => 'warning',
                    'url' => null,
                ];
            }
        }

        return $items;
    }

    public function hasNothingToReport(): bool
    {
        return $this->items() === [];
    }
}
