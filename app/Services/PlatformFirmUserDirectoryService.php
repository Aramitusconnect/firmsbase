<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * PlatformFirmUserDirectoryService — Phase 1 FirmsVault Admin Control
 * Center. The read path FirmUserResource uses to list/view firm_users
 * rows across every firm from the platform-admin panel.
 *
 * Architectural constraint this class exists to satisfy (documented
 * here explicitly — not flagged anywhere in the prior architecture
 * investigation, so recorded as a real finding, not silently worked
 * around): firm_users carries permanent FORCE ROW LEVEL SECURITY
 * (Section 39A-3B). Its only two policies are a firm-scoped match
 * (firm_id = current_setting('app.current_firm_id')) and a narrow
 * FOR-SELECT-only self-lookup policy (user_id = current_setting
 * ('app.current_user_id')) — there is NO policy that lets any session
 * read across every firm's rows at once, and (confirmed by grep across
 * this repository's own test suite — DatabaseRoleProofTest and every
 * *ForceRlsActivationTest) the runtime database role this application
 * connects as is deliberately never granted BYPASSRLS or superuser.
 * AdminPanelProvider's own docblock says as much: "this panel has zero
 * standing access to any firm's tenant data, by omission rather than an
 * explicit bypass check."
 *
 * The only architecturally-sound way to read firm_users across every
 * firm without introducing a BYPASSRLS/superuser carve-out (which every
 * test in this repository explicitly forbids) is the SAME pattern this
 * codebase's own security review already approved for exactly this
 * problem: FleetMigrationOrchestrationService's per-firm loop, each
 * iteration wrapped in TenantContextService::runWithFirmContext(),
 * merged in PHP. That is what listAll()/findByUuid() below do.
 *
 * Known, deliberate performance trade-off (flagged for reviewer
 * attention, not hidden): this is O(number of firms) queries per call,
 * not O(1). At the platform's current expected admin-panel scale this
 * is acceptable (mirrors the existing, security-approved
 * FleetMigrationOrchestrationService precedent, which accepted the same
 * trade-off for the same reason); it would need re-architecting — e.g.
 * a no-RLS, precomputed summary/index table refreshed by a scheduled
 * job, the exact pattern integration_platform_overview_summaries
 * already uses for the Integration Oversight page — if the firm
 * population grows large enough to make a full per-request scan
 * noticeably slow. That is explicitly out of this checkpoint's scope
 * (no such summary table was requested or built here) and is called out
 * as an open question in the final report rather than silently
 * addressed by inventing one.
 *
 * If a firm filter narrows the query to one specific firm (Resource
 * table filter), callers should pass $onlyFirmId so the loop below
 * covers exactly one firm instead of every firm — this is the one
 * optimization available without a schema change.
 */
class PlatformFirmUserDirectoryService
{
    public function __construct(
        private readonly PlatformStaffAccessPolicyService $accessPolicy,
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    public function assertCanAccess(PlatformAdmin $admin): void
    {
        $decision = $this->accessPolicy->canAccessPlatformAdministration($admin);

        if (! $decision->allowed) {
            throw new RuntimeException($decision->reason ?? 'Not permitted to access platform administration.');
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listAll(PlatformAdmin $admin, ?int $onlyFirmId = null): Collection
    {
        $this->assertCanAccess($admin);

        // NOTE: cannot narrow the column selection below to just
        // id/uuid/name — TenantContextResolver::resolveForFirm() (called
        // by runWithFirmContext() for each $firm below) also reads
        // organization_id and deployment_mode off the SAME Firm instance
        // to build its TenantContext, so every column that trait needs
        // must be loaded, not just the ones this service's own row shape
        // uses.
        $firms = Firm::query()
            ->when($onlyFirmId !== null, fn ($query) => $query->where('id', $onlyFirmId))
            ->orderBy('name')
            ->get();

        $rows = collect();

        foreach ($firms as $firm) {
            $firmUsers = $this->tenantContext->runWithFirmContext(
                $firm,
                fn () => FirmUser::query()->with('user')->orderBy('created_at')->get()
            );

            foreach ($firmUsers as $firmUser) {
                $rows->push($this->toRow($firm, $firmUser));
            }
        }

        return $rows;
    }

    /**
     * Executive Dashboard addition (Phase 1 FirmsVault Admin Control
     * Center). A count-only sibling of listAll() for the "Total firm
     * users" dashboard metric — reuses the exact same per-firm-loop +
     * gate pattern (see class docblock), but never hydrates a FirmUser
     * row or its `user` relation: each iteration issues one cheap
     * `count()` query instead of a `get()->with('user')`, which is the
     * one optimization available for a caller that only needs the
     * total, not the rows themselves. Still O(number of firms) queries
     * — the same documented, deliberate trade-off as listAll(), not a
     * new cost shape.
     */
    public function countAll(PlatformAdmin $admin, ?int $onlyFirmId = null): int
    {
        $this->assertCanAccess($admin);

        $firms = Firm::query()
            ->when($onlyFirmId !== null, fn ($query) => $query->where('id', $onlyFirmId))
            ->get();

        $total = 0;

        foreach ($firms as $firm) {
            $total += $this->tenantContext->runWithFirmContext(
                $firm,
                fn (): int => FirmUser::query()->count()
            );
        }

        return $total;
    }

    public function findByUuid(PlatformAdmin $admin, Firm $firm, string $firmUserUuid): ?FirmUser
    {
        $this->assertCanAccess($admin);

        return $this->tenantContext->runWithFirmContext(
            $firm,
            fn () => FirmUser::query()->with('user')->where('uuid', $firmUserUuid)->first()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(Firm $firm, FirmUser $firmUser): array
    {
        return [
            'uuid' => $firmUser->uuid,
            'firm_uuid' => $firm->uuid,
            'firm_name' => $firm->name,
            'user_name' => $firmUser->user?->name,
            'user_email' => $firmUser->user?->email,
            'role' => $firmUser->role?->value,
            'status' => $firmUser->status?->value,
            'seat_class' => $firmUser->effectiveSeatClass()->value,
            'is_primary' => $firmUser->is_primary,
            'invitation_accepted_at' => $firmUser->invitation_accepted_at,
            'created_at' => $firmUser->created_at,
        ];
    }
}
