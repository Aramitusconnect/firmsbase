<?php

declare(strict_types=1);

namespace App\Livewire\FinancialEvidence\Concerns;

use App\Models\Matter;
use App\Services\MatterAccessPolicyService;
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
}
