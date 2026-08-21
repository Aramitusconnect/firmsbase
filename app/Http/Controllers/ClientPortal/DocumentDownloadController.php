<?php

declare(strict_types=1);

namespace App\Http\Controllers\ClientPortal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientPortalUser;
use App\Models\Document;
use App\Services\DocumentSecurityService;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * DocumentDownloadController (Client Portal) — Follow-up 1 (Client
 * Portal Documents). Mirrors
 * App\Http\Controllers\Firm\DocumentDownloadController's exact shape
 * (resolve the authenticated actor, establish tenant context, resolve
 * the Document by uuid INSIDE that context, then check authorization
 * INSIDE the same context window before streaming) but for a
 * ClientPortalUser, using DocumentSecurityService::
 * canBeViewedInPortalBy() instead of canBeDownloadedBy() — visibility
 * and download-eligibility are the same boundary for a client; unlike
 * the Firm side, there is no separate "visible in the list but not
 * downloadable" concept.
 *
 * Deliberately NOT a public signed URL — Document's own docblock rule
 * ("private by default, never exposed via a public URL") applies here
 * exactly as it does on the Firm side. This route lives on the
 * session-authenticated Client Portal host/guard (see routes/web.php),
 * mirroring the Firm-side route's own middleware minimalism (auth +
 * throttle only — no ambient tenant-context middleware): this
 * controller is self-contained and establishes its own context rather
 * than depending on request-wide middleware ordering.
 *
 * `{document}` is deliberately NOT an implicit Eloquent-route-model-
 * bound parameter — identical reasoning to the Firm-side controller's
 * own docblock: `documents` carries FORCE ROW LEVEL SECURITY with no
 * self-lookup carve-out, and Laravel's route-model-binding
 * substitution (`SubstituteBindings`) runs ahead of any tenant-context
 * resolution in this app's middleware-priority order, so an implicit
 * binding would always resolve the row before any tenant context
 * exists and would always see zero rows. Instead, this controller
 * resolves the CURRENT ClientPortalUser's own `client_id` first (an
 * ordinary, unwrapped query — `client_portal_users` carries no RLS),
 * then their `Client.firm_id` via the same one-hop self-lookup
 * bootstrap `EstablishClientPortalTenantContext` itself uses
 * (`TenantContextService::withClientSelfLookupContext()`), and only
 * then looks up the target `Document` by its public uuid WITHIN that
 * firm's own tenant context — never by guessing a context from the
 * route parameter itself. A document belonging to a different firm
 * (or one this client has no matter grant for) is therefore genuinely
 * invisible under RLS/the composed authorization check, not merely
 * bound-then-rejected.
 */
class DocumentDownloadController extends Controller
{
    public function show(string $document): StreamedResponse
    {
        /** @var ClientPortalUser|null $portalUser */
        $portalUser = Auth::guard('client')->user();

        abort_if($portalUser === null, 403);

        $tenantContext = new TenantContextService;

        // Ordinary, unwrapped self-lookup (client_portal_users carries
        // no RLS) -> the one genuine RLS hop (clients is FORCE-RLS'd)
        // via the narrow self-lookup context, exactly like
        // EstablishClientPortalTenantContext resolves it for the whole
        // request elsewhere.
        $firmId = $tenantContext->withClientSelfLookupContext(
            $portalUser->client_id,
            fn () => Client::query()->findOrFail($portalUser->client_id)->firm_id,
        );

        // The lookup AND the authorization check both run inside the
        // same tenant-context window — canBeViewedInPortalBy()
        // eager-touches $document->matter, and Matter is itself
        // tenant-scoped/RLS-protected, so resolving it after this
        // context closed would see nothing.
        [$record, $isAuthorized] = $tenantContext->runWithFirmContext(
            $firmId,
            function () use ($document, $portalUser) {
                $record = Document::query()->where('uuid', $document)->first();

                if ($record === null) {
                    return [null, false];
                }

                return [$record, app(DocumentSecurityService::class)->canBeViewedInPortalBy($record, $portalUser)];
            },
        );

        abort_if($record === null, 404);

        abort_unless($isAuthorized, 403);

        return Storage::disk($record->storage_disk)->response(
            $record->storage_path,
            $record->original_filename,
        );
    }
}
