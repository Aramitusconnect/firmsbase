<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\PlatformRole;
use App\Models\SecurityEvent;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
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
 * Deterministic ordering (Phase 1 correction — every query below was
 * audited for this): `security_events.created_at` is a
 * `timestamp` column with the default (whole-second) precision — see
 * that table's own migration — so two events landing in the same
 * second is a real, not merely theoretical, tie. `id` is that table's
 * plain auto-increment bigint primary key, globally unique and
 * monotonic across every firm (confirmed by reading the migration
 * directly), so it is the correct tie-breaker, not a second, unrelated
 * ordering. Each per-firm query below orders by `created_at DESC, id
 * DESC`; the cross-firm merge in recentSecurityEvents() then re-sorts
 * the merged rows by the same (created_at, id) pair, so the final
 * result is fully deterministic regardless of which order Firm::query()
 * itself visits firms in (that query is still given an explicit
 * ->orderBy('name'), matching PlatformFirmUserDirectoryService::listAll()'s
 * own established convention, but it no longer needs to be for
 * recentSecurityEvents()'s own output to be stable — it is read-order
 * hygiene, not a correctness dependency, now that the id tie-break
 * exists). `id` is carried on each row purely as an internal sort key
 * and is stripped before returning — the return shape's own documented
 * column set (below) is unchanged.
 *
 * adminsWithoutConfirmedMfa() orders by `name`, which is NOT unique
 * (platform_admins has no unique constraint on it — only `email` is
 * unique) — an `id` tie-break is added for the same reason.
 * recentRoleChanges() orders by `updated_at DESC`, which collides for
 * the same whole-second-precision reason as security_events above — an
 * `id` tie-break is added there too.
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
     * FIRMSVAULT — REAL STAGING STABILIZATION, Objective A / Phase 2
     * correction: config/cache.php's `serializable_classes` stays at
     * its safe framework default (`false`, arbitrary object
     * deserialization from cache remains disabled). The cached closure
     * below therefore returns a PLAIN array of scalar
     * (string/int/bool/null) values only — `created_at` is serialized
     * to an ISO-8601 string, matching this codebase's own established
     * convention (see e.g. RlsSecurityReportService::generate(), which
     * already returns "clean, page-renderable structured data" for the
     * identical reason) — never a Carbon instance, never the Collection
     * itself. A plain scalar array serializes with zero class
     * references, so there is nothing for `serializable_classes` to
     * reject on any legitimate read, regardless of its own value. The
     * Collection (and each row's real Carbon `created_at`) is
     * reconstructed AFTER reading the scalar payload back out of cache —
     * on both the fresh-compute path and every subsequent cache-hit
     * path — so callers' return-type contract is unchanged.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function recentSecurityEvents(PlatformAdmin $admin, int $limit = 50): Collection
    {
        $this->assertCanAccess($admin);

        /** @var array<int, array<string, mixed>> $scalarRows */
        $scalarRows = Cache::remember(
            self::CACHE_KEY.'.'.$limit,
            self::CACHE_TTL_SECONDS,
            function () use ($limit): array {
                // See PlatformFirmUserDirectoryService::listAll()'s own
                // note: runWithFirmContext() below needs every column
                // TenantContextResolver::resolveForFirm() reads
                // (organization_id, deployment_mode), not just the ones
                // this method's own row shape uses.
                //
                // ->orderBy('name') mirrors listAll()'s own established
                // convention — see this class's docblock for why firm
                // iteration order no longer affects the final merged
                // result's determinism (the id tie-break below already
                // guarantees that), this is read-order hygiene only.
                $firms = Firm::query()->orderBy('name')->get();

                $rows = collect();

                foreach ($firms as $firm) {
                    $firmRows = $this->tenantContext->runWithFirmContext(
                        $firm,
                        fn () => SecurityEvent::query()
                            ->where('firm_id', $firm->id)
                            ->orderByDesc('created_at')
                            ->orderByDesc('id')
                            ->limit($limit)
                            ->get(['id', 'firm_id', 'actor_type', 'actor_id', 'event_type', 'category', 'created_at'])
                    );

                    foreach ($firmRows as $event) {
                        $rows->push([
                            // Internal sort key only — see this class's
                            // docblock. Stripped below before returning,
                            // so the returned row shape is unchanged.
                            'id' => $event->id,
                            'firm_name' => $firm->name,
                            'actor_type' => class_basename($event->actor_type),
                            'actor_id' => $event->actor_id,
                            'event_type' => $event->event_type,
                            'category' => $event->category,
                            // Scalar ISO-8601 string only — see this
                            // method's own docblock above. app.timezone
                            // is fixed to UTC (config/app.php), so this
                            // format is also fixed-width and sorts
                            // lexicographically identically to
                            // chronological order — the sort below
                            // remains correct operating on the string.
                            'created_at' => $event->created_at?->toIso8601String(),
                        ]);
                    }
                }

                // Two-key sort (created_at DESC, id DESC) — id is
                // security_events' globally unique, monotonic primary
                // key, so this is a total order: no two rows can ever
                // compare equal, regardless of which firm produced them
                // or what order they were pushed onto $rows in.
                return $rows
                    ->sort(fn (array $a, array $b): int => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']])
                    ->take($limit)
                    ->values()
                    ->map(fn (array $row): array => Arr::except($row, ['id']))
                    ->all();
            }
        );

        return collect($scalarRows)->map(function (array $row): array {
            $row['created_at'] = $row['created_at'] !== null
                ? Carbon::parse($row['created_at'])
                : null;

            return $row;
        });
    }

    /**
     * @return Collection<int, PlatformAdmin>
     */
    public function adminsWithoutConfirmedMfa(): Collection
    {
        return PlatformAdmin::query()
            ->whereNull('two_factor_confirmed_at')
            ->orderBy('name')
            ->orderBy('id')
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
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
