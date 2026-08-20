<?php

declare(strict_types=1);

namespace App\Http\Controllers\MyAttorney;

use App\Http\Controllers\Controller;
use App\Marketplace\Services\MarketplaceIntakeDocumentService;
use App\Marketplace\Services\MarketplaceIntakeService;
use App\Marketplace\Support\IntakeLinkPossession;
use App\Services\TenantContextService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * MarketplaceIntakeDocumentController — Mission 3 (MyAttorney
 * Conversion + AI Intake), checkpoint 7. `/intake/{uuid}/documents`,
 * reachable only from the resumable intake page a visitor already
 * holds the signed link for. Mirrors CorrectionRequestController's own
 * "resolve the public resource by its own identifier, never trust
 * anything else the browser sends" shape.
 *
 * The uuid alone resolves the intake (via MarketplaceIntakeService's
 * self-lookup context — the same two-hop pattern PublicIntakePage
 * uses); real Firm tenant context is only ever established AFTER that
 * resolution, inside MarketplaceIntakeDocumentService itself. Every
 * validation failure (unknown/expired/terminal intake, disallowed
 * file) degrades to a generic redirect — never a stack trace, never a
 * detail that could help an attacker probe for a valid uuid.
 */
class MarketplaceIntakeDocumentController extends Controller
{
    public function store(string $uuid, Request $request, MarketplaceIntakeService $intakes, MarketplaceIntakeDocumentService $documents): RedirectResponse
    {
        $intake = $intakes->resolveByUuid($uuid);

        if ($intake === null) {
            throw new NotFoundHttpException;
        }

        // A uuid is not the credential — the SIGNED link is. This route is
        // deliberately not signed itself (a signature in a POST URL leaks into
        // logs and referrers), so it requires the session to have actually
        // opened the signed link at some point. Without this, anyone who
        // learned a uuid — from an access log, a referrer header, a shared
        // screen — could attach files to a stranger's intake, which the firm
        // would then see as that prospect's own evidence.
        //
        // Same generic 404 as an unknown uuid: never distinguish "wrong
        // intake" from "no such intake".
        if (! IntakeLinkPossession::holds($uuid)) {
            throw new NotFoundHttpException;
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:25600'],
        ]);

        (new TenantContextService)->runWithFirmContext($intake->firm, function () use ($intake, $documents, $validated, $request) {
            if (! $intake->isResumable()) {
                return;
            }

            try {
                $documents->upload($intake, $validated['file'], $request->ip());
            } catch (\InvalidArgumentException) {
                // Disallowed extension/oversized file — surfaced as a
                // generic flash message, never the raw exception
                // message (which could disclose the exact allow-list).
                session()->flash('intake_document_rejected', true);
            }
        });

        return redirect()
            ->route('public.marketplace-intakes.show', $uuid)
            ->with('intake_document_uploaded', true);
    }
}
