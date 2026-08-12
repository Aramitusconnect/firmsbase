<?php

declare(strict_types=1);

namespace App\Http\Controllers\MyAttorney;

use App\Http\Controllers\Controller;
use App\Marketplace\Exceptions\MarketplaceIntakeIneligibleException;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Services\MarketplaceIntakeService;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * MarketplaceIntakeStartController — Mission 3A (MyAttorney
 * Launch-Flow Closure). `/firms/{slug}/start-intake`. The entry point
 * a visitor's "Start Secure Intake" button on a Firm's public profile
 * posts to. Mirrors CorrectionRequestController's own
 * "resolve slug -> DirectoryFirm -> canonical service call -> redirect"
 * shape exactly.
 *
 * Deliberately calls MarketplaceIntakeService::startForDirectoryFirm()
 * — the SAME Firm-eligibility gate (checkpoint 3) every other entry
 * point already goes through — never a direct
 * MarketplaceIntakeService::start() bypass. No practice-area selector
 * exists on the public Firm profile yet (out of this closure mission's
 * narrow scope — see the final report), so this always starts with a
 * null PracticeArea; the intake falls back to the platform-wide
 * default template exactly as IntakeTemplateService::
 * templateForPracticeArea() already documents.
 */
class MarketplaceIntakeStartController extends Controller
{
    public function store(string $slug, MarketplaceIntakeService $intakeService): RedirectResponse
    {
        $firm = DirectoryFirm::query()->where('slug', $slug)->first();

        if ($firm === null || ! $firm->isPubliclyVisible()) {
            throw new NotFoundHttpException;
        }

        try {
            $intake = $intakeService->startForDirectoryFirm($firm);
        } catch (MarketplaceIntakeIneligibleException) {
            // Never disclose the internal reason code to a public
            // visitor — matches that exception's own docblock
            // contract.
            return redirect()
                ->route('myattorney.firms.show', $slug)
                ->with('intake_unavailable', true);
        }

        return redirect()->to($intakeService->signedUrl($intake));
    }
}
