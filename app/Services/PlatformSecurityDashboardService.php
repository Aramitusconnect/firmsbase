<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\PlatformRole;
use App\Models\SecurityEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * PlatformSecurityDashboardService — Phase 1 FirmsVault Admin Control
 * Center. Read-only aggregation backing the new Security Dashboard page:
 * recent security_events activity, PlatformAdmins without confirmed
 * MFA, and recent platform_roles grant/revoke changes.
 *
 * security_events cross-firm read constraint (same class of problem as
 * PlatformFirmUserDirectoryService, see that class's own docblock for
 * the full explanation): security_events carries FORCE ROW LEVEL
 * SECURITY (Section 39A-3L Phase B6). Its SELECT policy allows a
 * session to see rows for its OWN active firm context, or — only when
 * NO context is active — rows with a NULL firm_id; it does NOT allow
 * seeing every firm's real (non-null-firm_id) rows in one query
 * regardless of context. Every existing security_events writer in this
 * codebase always sets a real firm_id (confirmed by reading
 * SupportAccessPolicyService and PlatformFirmIntegrationBoundedAccessService
 * directly), so a context-free read would surface nothing useful today.
 * recentSecurityEvents() below therefore uses the same per-firm loop +
 * merge pattern as PlatformFirmUserDirectoryService/
 * FleetMigrationOrchestrationService: for each firm, read that firm's
 * most recent $perFirmLimit rows under its own runWithFirmContext(),
 * then merge and re-sort/re-slice to the requested global limit. This
 * top-K-per-partition-then-merge shape is correct (any event that could
 * rank in the global top N is guaranteed to already be in some firm's
 * own top-N-or-fewer local slice) as long as $perFirmLimit >= the
 * requested global limit, which callers here always ensure.
 *
 * Cost/caching: like PlatformFirmUserDirectoryService, this is
 * O(number of firms) queries for recentSecurityEvents() — a real,
 * documented cost, not hidden. recentSecurityEvents() is wrapped in a
 * short (2 minute) Cache::remember(), the same general "don't run the
 * expensive cross-firm read on every request" discipline the mission
 * requires explicitly for the Tenant Isolation report (RlsSecurityReportService)
 * and applied here too since the underlying cost shape is the same —
 * this is a deliberate, documented choice (not a literal instruction
 * for this specific page), flagged in the final report as worth a
 * reviewer's confirmation rather than assumed correct silently.
 *
 * adminsWithoutConfirmedMfa()/recentRoleChanges() need no such
 * per-firm handling — platform_admins and platform_roles are both
 * cross-firm-by-design tables with no BelongsToTenant/RLS at all.
 *
 * Redaction discipline: recentSecurityEvents() below deliberately never
 * selects/returns the raw `metadata` JSON column — only event_type,
 * category, actor_type/id, firm name, and created_at. Some existing
 * metadata payloads carry operationally-sensitive-but-not-secret text
 * (e.g. a support-access reason string) that this dashboard has no
 * documented need to surface, and the mission's global "no secret
 * values ever rendered" rule is easiest to honor by never reading that
 * column here at all rather than trying to selectively redact its
 * contents per event_type.
 */
class PlatformSecurityDashboardService
{
    private const CACHE_KEY = 'platform_admin.security_dashboard.recent_security_events';

    private const CACHE_TTL_SECONDS = 120;

    public function __construct(
        private readonly PlatformStaffAccessPolicyService $accessPolicy,
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    public function assertCanAccess(PlatformAdmin $admin): void
    {
        $decision = $this->accessPolicy->canAccessSecurityLogs($admin);

        if (! $decision->allowed) {
            throw new RuntimeException($decision->reason ?? 'Not permitted to access security logs.');
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function recentSecurityEvents(PlatformAdmin $admin, int $limit = 50): Collection
    {
        $this->assertCanAccess($admin);

        return Cache::remember(
            self::CACHE_KEY.'.'.$limit,
            self::CACHE_TTL_SECONDS,
            function () use ($limit): Collection {
                // See PlatformFirmUserDirectoryService::listAll()'s own
                // note: runWithFirmContext() below needs every column
                // TenantContextResolver::resolveForFirm() reads
                // (organization_id, deployment_mode), not just the ones
                // this method's own row shape uses.
                $firms = Firm::query()->get();

                $rows = collect();

                foreach ($firms as $firm) {
                    $firmRows = $this->tenantContext->runWithFirmContext(
                        $firm,
                        fn () => SecurityEvent::query()
                            ->where('firm_id', $firm->id)
                            ->orderByDesc('created_at')
                            ->limit($limit)
                            ->get(['id', 'firm_id', 'actor_type', 'actor_id', 'event_type', 'category', 'created_at'])
                    );

                    foreach ($firmRows as $event) {
                        $rows->push([
                            'firm_name' => $firm->name,
                            'actor_type' => class_basename($event->actor_type),
                            'actor_id' => $event->actor_id,
                            'event_type' => $event->event_type,
                            'category' => $event->category,
                            'created_at' => $event->created_at,
                        ]);
                    }
                }

                return $rows
                    ->sortByDesc(fn (array $row) => $row['created_at'])
                    ->take($limit)
                    ->values();
            }
        );
    }

    /**
     * @return Collection<int, PlatformAdmin>
     */
    public function adminsWithoutConfirmedMfa(): Collection
    {
        return PlatformAdmin::query()
            ->whereNull('two_factor_confirmed_at')
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'email', 'is_active']);
    }

    /**
     * @return Collection<int, PlatformRole>
     */
    public function recentRoleChanges(int $limit = 25): Collection
    {
        return PlatformRole::query()
            ->with(['platformAdmin:id,name,email', 'grantedBy:id,name,email'])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();
    }
}
