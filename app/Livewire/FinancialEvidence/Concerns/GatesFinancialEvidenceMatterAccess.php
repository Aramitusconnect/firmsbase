<?php

declare(strict_types=1);

namespace App\Livewire\FinancialEvidence\Concerns;

use App\Enums\FirmUserStatus;
use App\Integrations\Services\FinancialIntegrationAccessPolicyService;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Services\MatterAccessPolicyService;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * GatesFinancialEvidenceMatterAccess — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.1). Every Financial
 * Evidence Workspace panel's own `mount(int $matterId)` calls
 * `$this->gateMatterAccess($matterId)` explicitly — independently
 * re-deriving the acting `FirmUser` and re-calling
 * `MatterAccessPolicyService::canAccessMatter()`, never trusting the
 * outer `FinancialEvidenceRelationManager::canViewForRecord()` gate
 * alone (the identical belt-and-suspenders discipline
 * `ViewFirmIntegration`'s own class docblock establishes for every
 * action closure on that page). A small, shared TRAIT (not a shared
 * Blade component — this codebase's own confirmed "no shared Blade
 * component library" convention, pre-construction inventory §1) since
 * seven panels would otherwise duplicate identical PHP logic verbatim.
 *
 * Deliberately explicit (`$this->gateMatterAccess($matterId)` called
 * from each panel's own `mount()`), not a Livewire trait-hook
 * (`mount{TraitName}`) — `$matterId` is the only property this check
 * needs and it is public (survives Livewire hydration across AJAX
 * round-trips), so `matter()` below re-resolves and re-checks the
 * Matter fresh on every call rather than caching a non-serializable
 * property that would silently go stale/null after the first request.
 */
trait GatesFinancialEvidenceMatterAccess
{
    public int $matterId;

    public function gateMatterAccess(int $matterId): void
    {
        $this->matterId = $matterId;

        // Fail fast at mount time too, so an unauthorized user never
        // even sees the panel shell render.
        $this->matter();
    }

    /**
     * Re-resolves and re-authorizes the Matter on EVERY call — never
     * cached on a property, so a mid-session revocation
     * (MatterAssignment removed, matter reassigned) takes effect on the
     * very next render, not just at initial mount.
     */
    protected function matter(): Matter
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            throw new AccessDeniedHttpException('No active firm membership.');
        }

        $matter = Matter::query()->find($this->matterId);

        if ($matter === null || ! app(MatterAccessPolicyService::class)->canAccessMatter(Auth::user(), $matter)) {
            throw new AccessDeniedHttpException('You do not have access to this matter\'s financial evidence.');
        }

        return $matter;
    }

    /**
     * The SECOND, independent gate — added by the C2 remediation.
     *
     * `matter()` above (MatterAccessPolicyService::canAccessMatter())
     * is NECESSARY BUT NOT SUFFICIENT for financial evidence: per that
     * service's own docblock it grants Paralegal, LegalAssistant,
     * Receptionist and BillingStaff access to any matter they hold a
     * routine, active `MatterAssignment` for. Financial evidence is a
     * strictly narrower tier — `FinancialIntegrationAccessPolicyService`
     * is the AUTHORITATIVE policy for it, and its own docblock is
     * explicit that "Paralegal, LegalAssistant, and Receptionist NEVER
     * receive any financial-tier integration permission, full stop."
     *
     * Both gates must pass (defense in depth); this one never replaces
     * the matter-access gate and never widens it. Called at mount, at
     * every render/data-load, at every table/query build, and
     * INDEPENDENTLY inside every mutation action — a mutation must
     * never rely on the page merely having been reachable.
     *
     * Deliberately re-derives the acting FirmUser SCOPED TO THE
     * MATTER'S OWN FIRM (mirroring MatterAccessPolicyService's own
     * lookup) rather than `User::activeFirmUser()`, which returns the
     * first active membership across ALL firms — for a user who is
     * staff at two firms that could otherwise evaluate the financial
     * tier against the wrong firm's role. Fail-closed: no active
     * membership in the matter's firm is a denial.
     */
    protected function gateFinancialTierAccess(Matter $matter): FirmUser
    {
        $firmUser = $this->actingFirmUserForMatter($matter);

        // Throws RuntimeException on denial and records an
        // `integration_governance.action_denied` timeline event — the
        // exact call the already-correct sibling panels (Notes/Reports/
        // Snapshots/TransactionSearch) make. No new check is invented
        // here and no role is inferred from a role-name string.
        //
        // Wrapped in the matter's firm context so the denial's own
        // audit event (TimelineEventRecorder, via recordDenied()) can
        // actually be written — timeline_events is FORCE-RLS protected
        // and these panels are reached with no ambient tenant context.
        (new TenantContextService)->runWithFirmContext(
            $matter->firm_id,
            fn () => app(FinancialIntegrationAccessPolicyService::class)->assertCanView($firmUser),
        );

        return $firmUser;
    }

    /**
     * Convenience for the overwhelmingly common "re-authorize, then use
     * the Matter" call site: runs BOTH gates in the mandatory order
     * (matter access first, financial tier second) and returns the
     * freshly re-authorized Matter.
     *
     * @return array{0: Matter, 1: FirmUser}
     */
    protected function gatedFinancialEvidenceContext(): array
    {
        $matter = $this->matter();

        return [$matter, $this->gateFinancialTierAccess($matter)];
    }

    /**
     * Same as gatedFinancialEvidenceContext() but for the many
     * read/display call sites that only need the Matter.
     */
    protected function gatedMatter(): Matter
    {
        return $this->gatedFinancialEvidenceContext()[0];
    }

    private function actingFirmUserForMatter(Matter $matter): FirmUser
    {
        $user = Auth::user();

        if ($user === null) {
            throw new AccessDeniedHttpException('No authenticated user.');
        }

        $firmUser = (new TenantContextService)->runWithFirmContext(
            $matter->firm_id,
            fn () => FirmUser::query()
                ->where('user_id', $user->id)
                ->where('firm_id', $matter->firm_id)
                ->where('status', FirmUserStatus::Active->value)
                ->first(),
        );

        if ($firmUser === null) {
            throw new AccessDeniedHttpException('No active firm membership in this matter\'s firm.');
        }

        return $firmUser;
    }
}
