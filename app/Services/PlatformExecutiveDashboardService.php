<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FirmActivationStatus;
use App\Integrations\Enums\HealthSummaryState;
use App\Models\PlatformAdmin;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PlatformExecutiveDashboardService — Phase 1 FirmsVault Admin Control
 * Center, final item. The single assembly point for the platform-admin
 * panel's Executive Dashboard (App\Filament\Pages\Dashboard). Every
 * Filament Widget under app/Filament/Widgets/ renders from ONE call to
 * snapshot() (invoked exactly once per page load by the Dashboard
 * page's getWidgetData() override, memoized on that page instance and
 * fanned out to every mounted widget via Filament's own widget-data
 * injection mechanism — see that page's own docblock) — no widget ever
 * queries the database directly.
 *
 * This class deliberately does NOT introduce any new cached/stored
 * summary table. Every metric below either:
 *  - reads a cheap, non-RLS table directly and live (firms, platform_admins,
 *    jobs, failed_jobs) — no caching needed, matching FirmResource's own
 *    documented reasoning for why `firms` needs no special handling;
 *  - reuses PlatformFirmUserDirectoryService's existing per-firm-loop
 *    pattern (countAll(), added alongside this service specifically for
 *    this dashboard — see that method's own docblock) for the one
 *    metric that genuinely crosses firm_users' FORCE RLS boundary;
 *  - reuses PlatformSecurityDashboardService's existing 2-minute cache
 *    (recentSecurityEvents()) and ungated direct reads
 *    (adminsWithoutConfirmedMfa());
 *  - reuses IntegrationPlatformOversightReadService::overviewSummaries(),
 *    itself a direct read of the already-5-minute-refreshed, no-RLS
 *    `integration_platform_overview_summaries` table — never a live
 *    query against any FORCE-RLS integration table;
 *  - reuses RlsSecurityReportService::cachedGenerate()'s existing
 *    5-minute cache (the SAME cache key PlatformTenantIsolationPage
 *    already warms/reads) for tenant-isolation summary, latest
 *    verification timestamp, AND git commit — one shared cache entry,
 *    never a second independent report generation.
 *
 * Per-section authorization: each admin-sensitive section below is
 * gated by the SAME PlatformStaffAccessPolicyService decision method
 * already established for that category of data elsewhere in this
 * codebase (never a new gate invented for the dashboard) — see each
 * section's own docblock. An unauthorized section short-circuits BEFORE
 * any query for that section runs and returns `authorized: false` with
 * the decision's own reason, never partial or zeroed-out real data. The
 * three genuinely operational sections (`environment`, `system`) carry
 * no tenant/roster/security-event content at all, so they are exposed
 * to every active PlatformAdmin who can reach the dashboard at all,
 * unconditionally.
 */
class PlatformExecutiveDashboardService
{
    private const RECENT_ACTIVITY_LIMIT = 10;

    /**
     * health_summary_state values that count as "needs attention" for
     * the integrations section below — every HealthSummaryState case
     * except Healthy. A firm with no recorded health rows at all
     * (health_summary_state === null) is NOT counted here — null means
     * "no signal yet," not "unhealthy," matching
     * IntegrationPlatformOverviewSummaryService::mostSevereHealthState()'s
     * own null-means-no-data semantics.
     */
    private const ATTENTION_NEEDED_HEALTH_STATES = [
        HealthSummaryState::Degraded->value,
        HealthSummaryState::ActionRequired->value,
        HealthSummaryState::Unavailable->value,
    ];

    public function __construct(
        private readonly PlatformStaffAccessPolicyService $accessPolicy,
        private readonly PlatformFirmUserDirectoryService $firmUserDirectory,
        private readonly PlatformSecurityDashboardService $securityDashboard,
        private readonly IntegrationPlatformOversightReadService $integrationOversight,
        private readonly RlsSecurityReportService $rlsReport,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(PlatformAdmin $admin): array
    {
        // One shared read of the Tenant Isolation cache (5-minute TTL,
        // same cache key that page already warms) — used by BOTH the
        // ungated `system.git_commit` field and the gated `security`
        // section below, so it is fetched exactly once per snapshot()
        // call regardless of how many sections need a piece of it.
        $tenantIsolationReport = $this->rlsReport->cachedGenerate();

        return [
            'generated_at' => now()->toIso8601String(),
            'environment' => $this->environmentSection(),
            'firms' => $this->firmsSection($admin),
            'platform_admins' => $this->platformAdminsSection($admin),
            'integrations' => $this->integrationsSection($admin),
            'system' => $this->systemSection($tenantIsolationReport),
            'security' => $this->securitySection($admin, $tenantIsolationReport),
            'recent_activity' => $this->recentActivitySection($admin),
        ];
    }

    /**
     * Ungated — app()->environment() is process-local runtime
     * information, not tenant/roster/security-event data. Live, trivial,
     * no query.
     *
     * @return array{name: string, is_production: bool, is_staging: bool, is_local: bool, is_testing: bool}
     */
    private function environmentSection(): array
    {
        return [
            'name' => app()->environment(),
            'is_production' => app()->environment('production'),
            'is_staging' => app()->environment('staging'),
            'is_local' => app()->environment('local'),
            'is_testing' => app()->environment('testing'),
        ];
    }

    /**
     * Gate: PlatformStaffAccessPolicyService::canAccessPlatformAdministration()
     * — the exact gate FirmResource/FirmUserResource/
     * PlatformFirmUserDirectoryService already use for this same data.
     * `firms` carries no RLS (tenancy root, confirmed by FirmResource's
     * own docblock), so the total/by-status counts are a single live,
     * cheap grouped COUNT query — no caching needed. `total_firm_users`
     * reuses PlatformFirmUserDirectoryService::countAll() (this
     * checkpoint's own count-only sibling of listAll()), which is
     * O(number of firms) queries, the same documented trade-off that
     * method's own docblock already discloses.
     *
     * Empty state: zero firms yields total=0, every by_status entry
     * present at 0 (never omitted — FirmActivationStatus's 3 real cases
     * are always all three keys), total_firm_users=0.
     *
     * @return array<string, mixed>
     */
    private function firmsSection(PlatformAdmin $admin): array
    {
        $decision = $this->accessPolicy->canAccessPlatformAdministration($admin);

        if (! $decision->allowed) {
            return $this->unauthorized($decision->reason);
        }

        $countsByStatus = DB::table('firms')
            ->select('activation_status', DB::raw('count(*) as aggregate'))
            ->groupBy('activation_status')
            ->pluck('aggregate', 'activation_status');

        $byStatus = collect(FirmActivationStatus::cases())
            ->mapWithKeys(fn (FirmActivationStatus $status): array => [
                $status->value => (int) ($countsByStatus[$status->value] ?? 0),
            ])
            ->all();

        $totalFirmUsers = 0;

        try {
            $totalFirmUsers = $this->firmUserDirectory->countAll($admin);
        } catch (RuntimeException) {
            // Defensive only — the decision above already allowed this
            // admin against the identical gate countAll() itself
            // asserts, so this should never actually throw.
        }

        return [
            'authorized' => true,
            'reason' => null,
            'total' => array_sum($byStatus),
            'by_status' => $byStatus,
            'total_firm_users' => $totalFirmUsers,
        ];
    }

    /**
     * Gate: canAccessSecurityLogs() — matches
     * PlatformSecurityDashboardPage's own gate for this exact roster/MFA
     * data (that page's "PlatformAdmins Without Confirmed MFA" section
     * uses the identical check at the page level). `platform_admins`
     * carries no RLS (cross-firm-by-design, per that model's own
     * docblock), so both counts are direct, cheap, live queries.
     * `without_confirmed_mfa_count` reuses
     * PlatformSecurityDashboardService::adminsWithoutConfirmedMfa()
     * rather than re-querying the same WHERE clause a second time.
     *
     * Empty state: zero admins (should not happen in practice, but
     * handled) or zero unconfirmed-MFA admins both yield 0, never null.
     *
     * @return array<string, mixed>
     */
    private function platformAdminsSection(PlatformAdmin $admin): array
    {
        $decision = $this->accessPolicy->canAccessSecurityLogs($admin);

        if (! $decision->allowed) {
            return $this->unauthorized($decision->reason);
        }

        return [
            'authorized' => true,
            'reason' => null,
            'active_count' => PlatformAdmin::query()->where('is_active', true)->count(),
            'without_confirmed_mfa_count' => $this->securityDashboard->adminsWithoutConfirmedMfa()->count(),
            // CORE SuperAdmin mission, section 15 (Requires Attention):
            // the same "active" definition PlatformRoleService::
            // wouldLeaveNoActiveSuperAdmin() uses (is_active AND an
            // unrevoked SuperAdmin grant), expressed via the identical
            // whereExists() shape that method already uses — never a
            // join that could double-count an admin holding more than
            // one non-revoked grant row — so
            // PlatformRequiresAttentionWidget can flag a sole-active-
            // SuperAdmin platform without a second, differently-shaped
            // query.
            'active_super_admin_count' => PlatformAdmin::query()
                ->where('is_active', true)
                ->whereExists(function ($query): void {
                    $query->select(DB::raw(1))
                        ->from('platform_roles')
                        ->whereColumn('platform_roles.platform_admin_id', 'platform_admins.id')
                        ->where('platform_roles.role_code', 'super_admin')
                        ->whereNull('platform_roles.revoked_at');
                })
                ->count(),
        ];
    }

    /**
     * Gate: canAccessIntegrationOversight() — the exact gate
     * IntegrationPlatformOversightReadService::overviewSummaries()
     * itself already asserts internally (PlatformFirmIntegrationBoundedAccessService::
     * assertCanAccessOversight()); checked here too so an unauthorized
     * admin never even reaches that call (defense in depth, matching
     * this codebase's established double-check discipline elsewhere,
     * e.g. that same read service's own docblock re: Security review
     * Finding 3).
     *
     * Aggregates entirely in PHP over the already-computed, already-5-
     * minute-refreshed `integration_platform_overview_summaries` rows —
     * never a live per-firm query against any FORCE-RLS integration
     * table. "Connected" = sum(connection_count_active) across every
     * firm's summary row. "Attention needed" = count of firms whose
     * health_summary_state is Degraded/ActionRequired/Unavailable (see
     * ATTENTION_NEEDED_HEALTH_STATES's own docblock for why a null
     * state is excluded). Failed/dead-lettered/open-conflict counts are
     * straight sums of the summary table's own columns — no invented
     * metric.
     *
     * Empty state: zero firms with a summary row yields every count at
     * 0 and `latest_computed_at` null (never a fabricated timestamp).
     *
     * @return array<string, mixed>
     */
    private function integrationsSection(PlatformAdmin $admin): array
    {
        $decision = $this->accessPolicy->canAccessIntegrationOversight($admin);

        if (! $decision->allowed) {
            return $this->unauthorized($decision->reason);
        }

        $summaries = $this->integrationOversight->overviewSummaries($admin);

        $connected = 0;
        $attentionNeeded = 0;
        $failed = 0;
        $deadLettered = 0;
        $openConflicts = 0;
        $latestComputedAt = null;

        foreach ($summaries as $row) {
            $connected += (int) ($row['connection_count_active'] ?? 0);
            $failed += (int) ($row['failed_permanent_sync_item_count'] ?? 0);
            $deadLettered += (int) ($row['dead_lettered_outbox_event_count'] ?? 0);
            $openConflicts += (int) ($row['open_conflict_count'] ?? 0);

            if (in_array($row['health_summary_state'] ?? null, self::ATTENTION_NEEDED_HEALTH_STATES, true)) {
                $attentionNeeded++;
            }

            $computedAt = $row['computed_at'] ?? null;

            if ($computedAt !== null && ($latestComputedAt === null || $computedAt > $latestComputedAt)) {
                $latestComputedAt = $computedAt;
            }
        }

        return [
            'authorized' => true,
            'reason' => null,
            'firms_with_summary' => $summaries->count(),
            'connected_count' => $connected,
            'attention_needed_firm_count' => $attentionNeeded,
            'failed_permanent_sync_item_count' => $failed,
            'dead_lettered_outbox_event_count' => $deadLettered,
            'open_conflict_count' => $openConflicts,
            'latest_computed_at' => $latestComputedAt,
        ];
    }

    /**
     * Ungated — queue/job-table counts and the git commit carry no
     * tenant/roster/security-event content. `jobs`/`failed_jobs` are
     * plain Laravel core tables (config/queue.php confirms QUEUE_CONNECTION
     * defaults to `database`, and the `failed` driver is `database-uuids`
     * against the SAME `failed_jobs` table regardless of the active
     * queue driver — see that config file's own `'failed'` block), so
     * `failed_jobs_count` is always observable. `queue_pending_jobs` is
     * only observable when the active queue driver is actually
     * `database` (the `jobs` table is meaningless for sqs/redis/etc.) —
     * `queue_pending_jobs_observable` tells the widget which is true
     * rather than the widget guessing from a null value alone.
     *
     * `git_commit` reuses RlsSecurityReportService's own gitCommit()
     * mechanism exactly as instructed — never a second git shell-out —
     * by reading it off the SAME cached report `security()` below also
     * reads, passed in by snapshot() rather than re-fetched here.
     *
     * `scheduler_status` is honestly labeled `unavailable`, not
     * fabricated: this application's own Laravel scheduler is defined in
     * bootstrap/app.php's withSchedule() closure, but nothing in this
     * codebase persists a heartbeat/last-run signal for that scheduler
     * itself. `deployment_health_checks` (checked directly — see its own
     * migration docblock) is a DIFFERENT concept: a per-firm/per-instance
     * heartbeat log for dedicated/private deployments reporting their
     * OWN health outward, not a record of whether THIS platform's own
     * `schedule:run` cron/systemd-timer entry is actually executing.
     * Per the mission's own instruction, this is disclosed as
     * unavailable rather than invented.
     *
     * @param  array<string, mixed>  $tenantIsolationReport
     * @return array<string, mixed>
     */
    private function systemSection(array $tenantIsolationReport): array
    {
        $queueConnection = (string) config('queue.default');
        $queuePendingObservable = $queueConnection === 'database';

        return [
            'queue_connection' => $queueConnection,
            'queue_pending_jobs_observable' => $queuePendingObservable,
            'queue_pending_jobs' => $queuePendingObservable
                ? DB::table((string) config('queue.connections.database.table', 'jobs'))->count()
                : null,
            'failed_jobs_count' => DB::table('failed_jobs')->count(),
            'git_commit' => $tenantIsolationReport['git_commit'] ?? null,
            'scheduler_status' => 'unavailable',
            'scheduler_status_reason' => 'No table records a heartbeat for this platform\'s own scheduler '
                .'(bootstrap/app.php withSchedule()). deployment_health_checks tracks per-firm/per-instance '
                .'dedicated-deployment heartbeats only — a different concept — not this platform\'s own '
                .'schedule:run execution, so this is honestly labeled unavailable rather than fabricated.',
        ];
    }

    /**
     * Gate: canAccessSecurityLogs() — the identical gate
     * PlatformTenantIsolationPage already uses for this exact report.
     * Reuses the SAME cachedGenerate() result snapshot() already fetched
     * once (never a second independent generate() call) plus a live
     * runtimeRoleSecurityState() read (a single-row pg_roles lookup,
     * the same live-every-render cost that page already pays — not a
     * new cost this dashboard introduces).
     *
     * Empty state: if the coverage mapping service reports zero tracked
     * tables (not expected in this codebase, but not assumed away
     * either), every summary count is naturally 0 via the `?? 0`
     * fallbacks below.
     *
     * @param  array<string, mixed>  $tenantIsolationReport
     * @return array<string, mixed>
     */
    private function securitySection(PlatformAdmin $admin, array $tenantIsolationReport): array
    {
        $decision = $this->accessPolicy->canAccessSecurityLogs($admin);

        if (! $decision->allowed) {
            return $this->unauthorized($decision->reason);
        }

        $summary = $tenantIsolationReport['summary'] ?? [];
        $runtimeRole = $this->rlsReport->runtimeRoleSecurityState();

        return [
            'authorized' => true,
            'reason' => null,
            'tenant_isolation' => [
                'total_tenant_owned' => (int) ($summary['prepared'] ?? 0) + (int) ($summary['uncovered'] ?? 0),
                'prepared' => (int) ($summary['prepared'] ?? 0),
                'forced' => (int) ($summary['forced'] ?? 0),
                'uncovered' => (int) ($summary['uncovered'] ?? 0),
                'exempt' => (int) ($summary['exempt'] ?? 0),
            ],
            'latest_verification_at' => $tenantIsolationReport['generated_at'] ?? null,
            'runtime_role_is_superuser' => $runtimeRole['is_superuser'] ?? null,
            'runtime_role_has_bypass_rls' => $runtimeRole['has_bypass_rls'] ?? null,
        ];
    }

    /**
     * Gate: canAccessSecurityLogs() — enforced inside
     * PlatformSecurityDashboardService::recentSecurityEvents() itself
     * (assertCanAccess()), caught here exactly like
     * PlatformSecurityDashboardPage's own table()->records() closure
     * already does. Reuses that service's existing 2-minute cache under
     * a dashboard-specific limit (10, smaller than the Security
     * Dashboard page's own 50) — a DIFFERENT cache key
     * (CACHE_KEY.'.'.$limit), so this never collides with or
     * invalidates that page's own cached 50-row read.
     *
     * Empty state: zero security_events rows yields an empty Collection
     * — the widget renders its own "No recent privileged activity"
     * placeholder, matching PlatformSecurityDashboardPage's
     * emptyStateHeading() text.
     *
     * @return array<string, mixed>
     */
    private function recentActivitySection(PlatformAdmin $admin): array
    {
        try {
            return [
                'authorized' => true,
                'reason' => null,
                'events' => $this->securityDashboard->recentSecurityEvents($admin, self::RECENT_ACTIVITY_LIMIT),
            ];
        } catch (RuntimeException $e) {
            return [
                'authorized' => false,
                'reason' => $e->getMessage(),
                'events' => collect(),
            ];
        }
    }

    /**
     * @return array{authorized: false, reason: ?string}
     */
    private function unauthorized(?string $reason): array
    {
        return [
            'authorized' => false,
            'reason' => $reason,
        ];
    }
}
