<?php

declare(strict_types=1);

namespace App\Http\Controllers\MyAttorney;

use App\Http\Controllers\Controller;
use App\Marketplace\Enums\CorrectionType;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Services\MarketplaceCorrectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * CorrectionRequestController — Mission 2 (MyAttorney Marketplace
 * Core), section 51. `/firms/{slug}/report-correction`. Genuinely
 * public and unauthenticated — MyAttorney never receives a Firm auth
 * cookie (section 63), so a reporter here is always anonymous from
 * this controller's point of view; MarketplaceCorrectionService's
 * `$reporter` FirmUser parameter exists for the checkpoint 10 case
 * (a claimed Firm's own owner reporting an issue from the Firm app),
 * never reachable from this public route.
 *
 * Same 404-identical-for-hidden-listings rule as FirmProfileController
 * — a correction cannot be reported against a listing that isn't
 * itself publicly visible.
 */
class CorrectionRequestController extends Controller
{
    public function create(string $slug): View
    {
        $firm = $this->publicFirmOrFail($slug);

        return view('myattorney.firms.report-correction', [
            'firm' => $firm,
            'correctionTypes' => CorrectionType::cases(),
        ]);
    }

    public function store(string $slug, Request $request, MarketplaceCorrectionService $corrections): RedirectResponse
    {
        $firm = $this->publicFirmOrFail($slug);

        $validated = $request->validate([
            'correction_type' => ['required', 'string', 'in:'.implode(',', array_map(fn (CorrectionType $t) => $t->value, CorrectionType::cases()))],
            'description' => ['required', 'string', 'max:2000'],
            'reporter_name' => ['nullable', 'string', 'max:255'],
            'reporter_email' => ['nullable', 'email', 'max:255'],
        ]);

        $corrections->submit(
            $firm,
            CorrectionType::from($validated['correction_type']),
            $validated['description'],
            $validated['reporter_name'] ?? null,
            $validated['reporter_email'] ?? null,
        );

        return redirect()
            ->route('myattorney.firms.show', $firm->slug)
            ->with('correction_submitted', true);
    }

    private function publicFirmOrFail(string $slug): DirectoryFirm
    {
        $firm = DirectoryFirm::query()->where('slug', $slug)->first();

        if ($firm === null || ! $firm->isPubliclyVisible()) {
            throw new NotFoundHttpException;
        }

        return $firm;
    }
}
