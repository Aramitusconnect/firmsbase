<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Marketplace\Enums\MarketplaceCapability;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\ValueObjects\MarketplaceIntakeEligibility;
use App\Models\PracticeArea;
use Illuminate\Support\Collection;

/**
 * MarketplaceIntakeEligibilityService — Mission 3 (MyAttorney
 * Conversion + AI Intake), checkpoint 3. The single place that
 * decides whether a DirectoryFirm listing may receive Secure Intake
 * data. Every check here is server-side, deterministic, and re-derived
 * from the stored DirectoryFirm row on every call — never trusts a
 * capability/eligibility flag the browser might send (section
 * requirement: the browser must never be authoritative for Firm ID,
 * secure_intake capability, membership state, practice-area
 * eligibility, or intake ownership).
 *
 * Checks, in order (first failure wins — no partial-credit result):
 *   1. The listing resolves to a real canonical Firm (firm_id set).
 *   2. The listing is claimed (is_claimed) — an unclaimed listing has
 *      no verified owner to review a lead, and per Mission 2 section
 *      16 ("unclaimed listings get zero privileged capabilities")
 *      must never receive private intake data (mission requirement).
 *   3. MarketplaceCapabilityService grants SecureIntake for this
 *      listing's current profile level — the ONLY capability check
 *      this service performs; it never re-derives is_claimed/
 *      is_marketplace_member itself (that would duplicate
 *      MarketplaceCapabilityService's own single-source-of-truth
 *      role).
 *   4. The listing is publicly Published (not Draft/Suspended/
 *      Removed/Archived).
 *   5. The listing is currently accepting_inquiries.
 */
class MarketplaceIntakeEligibilityService
{
    public function __construct(
        private readonly MarketplaceCapabilityService $capabilityService = new MarketplaceCapabilityService,
    ) {}

    /**
     * The practice areas a visitor may start an intake in for this listing.
     *
     * Drawn from the firm's OWN published associations
     * (directory_firm_practice_areas, which MyAttorneyProfilePage writes as
     * `firm_submitted`) — never from the platform-wide taxonomy. A firm that
     * has not said it practices in an area must never receive an intake filed
     * under it.
     *
     * Filtered to active AND marketplace-visible. The firm-side selector only
     * offers marketplace-visible areas today, so the second filter is
     * belt-and-braces against imported or historical rows; is_active matters
     * on its own because matter types hang off the practice area, and an
     * inactive area would produce an intake nothing can be converted into.
     *
     * @return Collection<int, PracticeArea>
     */
    public function eligiblePracticeAreas(DirectoryFirm $directoryFirm): Collection
    {
        return $directoryFirm->practiceAreas()
            ->where('practice_areas.is_active', true)
            ->where('practice_areas.is_marketplace_visible', true)
            ->orderBy('practice_areas.sort_order')
            ->orderBy('practice_areas.name')
            ->get();
    }

    /**
     * Resolve a visitor-supplied practice area against what this listing
     * actually offers.
     *
     * Returns null for anything the firm does not publish — a forged id, an
     * unpublished area, another firm's area, or a non-existent one. The caller
     * treats null as a refusal; this never falls back to "close enough".
     */
    public function resolveEligiblePracticeArea(DirectoryFirm $directoryFirm, mixed $practiceAreaId): ?PracticeArea
    {
        if (! is_numeric($practiceAreaId)) {
            return null;
        }

        return $this->eligiblePracticeAreas($directoryFirm)
            ->firstWhere('id', (int) $practiceAreaId);
    }

    public function evaluate(DirectoryFirm $directoryFirm): MarketplaceIntakeEligibility
    {
        if ($directoryFirm->firm_id === null) {
            return MarketplaceIntakeEligibility::ineligible('no_canonical_firm');
        }

        if (! $directoryFirm->is_claimed) {
            return MarketplaceIntakeEligibility::ineligible('unclaimed');
        }

        if (! $this->capabilityService->has($directoryFirm, MarketplaceCapability::SecureIntake)) {
            return MarketplaceIntakeEligibility::ineligible('not_capable');
        }

        if (! $directoryFirm->isPubliclyVisible()) {
            return MarketplaceIntakeEligibility::ineligible('not_published');
        }

        if (! $directoryFirm->accepting_inquiries) {
            return MarketplaceIntakeEligibility::ineligible('not_accepting_inquiries');
        }

        return MarketplaceIntakeEligibility::eligible();
    }
}
