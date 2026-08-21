<?php

declare(strict_types=1);

namespace App\Http\Controllers\Firm;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\DocumentSecurityService;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * DocumentDownloadController — Mission 3 (Document Center Completion),
 * section 3.5. The one route DocumentSecurityService::canBeDownloadedBy()
 * (Mission 1C, section 17) was built for but never had — a real,
 * session-authenticated download endpoint. Deliberately NOT a public
 * signed URL: Document's own docblock is explicit that a document is
 * "private by default, never exposed via a public URL" (project rule).
 * This route lives on the firm-panel-authenticated host/guard instead
 * (see routes/web.php), and canBeDownloadedBy() — not the route's
 * middleware alone — is the real authorization boundary, composing
 * MatterAccessPolicyService for a matter-scoped document and a plain
 * active-FirmUser check for a firm-level one, exactly like it already
 * does for every other actor.
 *
 * `{document}` is deliberately NOT an implicit Eloquent-route-model-
 * bound parameter — mirrors OAuthConnectionController's own, already-
 * established reasoning for the exact same shape of problem
 * (documented on that controller): `documents` carries permanent
 * FORCE ROW LEVEL SECURITY with no self-lookup carve-out, and
 * Laravel's route-model-binding substitution (`SubstituteBindings`)
 * runs ahead of any custom tenant-context middleware in this app's
 * middleware-priority order (confirmed empirically — `bootstrap/
 * app.php`, where that global ordering is set via
 * `prependToPriorityList()`, is frozen for this mission), so an
 * implicit binding would always resolve the row before any tenant
 * context exists and would always see zero rows. Instead, this
 * controller resolves the CURRENT user's own single active firm
 * membership first (`User::activeFirmUser()`, the same self-lookup
 * bootstrap `EstablishFirmTenantContext` itself uses for Filament
 * panel access), then looks up the target `Document` by its public
 * uuid WITHIN that firm's own tenant context — never by guessing a
 * context from the route parameter itself. A document belonging to a
 * different firm is therefore genuinely invisible under RLS (404)
 * rather than bound-then-rejected; a same-firm but unauthorized
 * request still reaches `canBeDownloadedBy()` and gets a real 403.
 */
class DocumentDownloadController extends Controller
{
    public function show(string $document): StreamedResponse
    {
        $user = Auth::user();

        abort_if($user === null, 403);

        $firmUser = $user->activeFirmUser();

        abort_if($firmUser === null, 403);

        // The lookup AND the authorization check both run inside the
        // same tenant-context window — canBeDownloadedBy() eager-
        // touches $document->matter, and Matter is itself tenant-
        // scoped/RLS-protected, so resolving it after this context
        // closed would see nothing (the exact class of bug this
        // single-callback shape avoids).
        [$record, $isAuthorized] = (new TenantContextService)->runWithFirmContext(
            $firmUser->firm_id,
            function () use ($document, $user) {
                $record = Document::query()->where('uuid', $document)->first();

                if ($record === null) {
                    return [null, false];
                }

                return [$record, app(DocumentSecurityService::class)->canBeDownloadedBy($record, $user)];
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
