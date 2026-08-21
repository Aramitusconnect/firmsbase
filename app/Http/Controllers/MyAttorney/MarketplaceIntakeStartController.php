<?php

declare(strict_types=1);

namespace App\Http\Controllers\MyAttorney;

use App\Http\Controllers\Controller;
use App\Marketplace\Exceptions\MarketplaceIntakeIneligibleException;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Services\MarketplaceIntakeEligibilityService;
use App\Marketplace\Services\MarketplaceIntakeService;
use App\Marketplace\ViewModels\PublicFirmProfile;
use App\Services\CanonicalUrlService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * MarketplaceIntakeStartController — `/firms/{slug}/start-intake`. The entry
 * point a visitor's "Start Secure Intake" button posts to.
 *
 * PRACTICE AREA IS CHOSEN HERE, NOT LATER. Every intake this controller
 * previously created carried practice_area_id = null, because no selector
 * existed and it called startForDirectoryFirm() with no area. That looked
 * harmless at intake time and broke the funnel at its end: ConvertIntakeAction
 * populates its required Matter Type select from the intake's practice area,
 * so a null one offers zero options and the prospect can never become a Client
 * and Matter. Repairing it at conversion would mean asking the firm to guess
 * months later what the visitor meant; asking the visitor now is both cheaper
 * and more accurate.
 *
 * Three cases, from the firm's OWN published practice areas:
 *
 *   one   used automatically — a selector with a single option is a click that
 *         teaches the visitor nothing
 *   many  the visitor is asked "What do you need help with?" before any intake
 *         row exists, so an abandoned choice leaves nothing behind
 *   none  no intake is created at all. A firm that has published no practice
 *         area cannot receive a convertible intake, and creating one anyway
 *         would just move the dead end further down the funnel where it costs
 *         the prospect their time. Fails closed with a plain message, never a
 *         platform-wide default: guessing an area would file a real matter
 *         under a specialization the firm never claimed.
 *
 * A submitted practice_area_id is resolved against THIS listing's own
 * associations, so a forged, unpublished, or another firm's area is refused
 * rather than trusted.
 */
class MarketplaceIntakeStartController extends Controller
{
    public function store(
        string $slug,
        Request $request,
        MarketplaceIntakeService $intakeService,
        MarketplaceIntakeEligibilityService $eligibility,
    ): RedirectResponse {
        $firm = $this->resolveVisibleFirm($slug);

        $areas = $eligibility->eligiblePracticeAreas($firm);

        if ($areas->isEmpty()) {
            // Case C. Reported to the firm as a configuration gap, shown to the
            // visitor as a plain "not available right now".
            return redirect()
                ->route('myattorney.firms.show', $slug)
                ->with('intake_unavailable', true);
        }

        $submitted = $request->input('practice_area_id');

        if ($areas->count() === 1 && $submitted === null) {
            $practiceArea = $areas->first();
        } else {
            $practiceArea = $eligibility->resolveEligiblePracticeArea($firm, $submitted);
        }

        if ($practiceArea === null) {
            // Either the visitor has not chosen yet (Case B, first pass), or
            // they sent something this listing does not offer. Both land on the
            // chooser: it is the truthful next step for the first, and it
            // discloses nothing to the second.
            return redirect()->route('myattorney.firms.start-intake.choose', $slug);
        }

        try {
            $intake = $intakeService->startForDirectoryFirm($firm, $practiceArea);
        } catch (MarketplaceIntakeIneligibleException) {
            // Never disclose the internal reason code to a public visitor.
            return redirect()
                ->route('myattorney.firms.show', $slug)
                ->with('intake_unavailable', true);
        }

        return redirect()->to($intakeService->signedUrl($intake));
    }

    /**
     * Case B's question. A GET so it can be linked, refreshed and backed out of
     * without creating anything.
     */
    public function choose(
        string $slug,
        CanonicalUrlService $hosts,
        MarketplaceIntakeEligibilityService $eligibility,
    ): View|RedirectResponse {
        $firm = $this->resolveVisibleFirm($slug);
        $areas = $eligibility->eligiblePracticeAreas($firm);

        if ($areas->isEmpty()) {
            return redirect()
                ->route('myattorney.firms.show', $slug)
                ->with('intake_unavailable', true);
        }

        $profile = PublicFirmProfile::fromModel($firm);

        return view('myattorney.firms.start-intake', [
            'profile' => $profile,
            'practiceAreas' => $areas,
            'canonicalUrl' => $hosts->myAttorneyFirmUrl($profile->slug),
        ]);
    }

    /**
     * A non-existent OR non-publicly-visible slug 404s identically — never
     * distinguishes "doesn't exist" from "exists but hidden".
     */
    private function resolveVisibleFirm(string $slug): DirectoryFirm
    {
        $firm = DirectoryFirm::query()->where('slug', $slug)->first();

        if ($firm === null || ! $firm->isPubliclyVisible()) {
            throw new NotFoundHttpException;
        }

        return $firm;
    }
}
