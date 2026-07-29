<?php

declare(strict_types=1);

namespace App\Http\Controllers\ClientPortal;

use App\Filament\ClientPortal\Pages\PlaidDateRangeConfirmationPage;
use App\Http\Controllers\Controller;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\ProviderConnectionService;
use App\Models\ClientPortalUser;
use App\Models\FinancialEvidenceMatterRequest;
use App\Models\Matter;
use App\Services\ClientPortalMatterAccessPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * PlaidExchangeController — FirmsVault Live Integrations, Checkpoint 4
 * ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §4.3;
 * checkpoint4-combined-design.md §1.3). The one new non-Filament HTTP
 * endpoint this checkpoint adds — `portal/plaid/exchange`, corrected
 * per §1.3's found-and-fixed route-path drift (the panel's actual
 * mounted URL path is `/portal`, not `/client-portal`). The route's own
 * middleware stack carries the same `EncryptCookies`/`StartSession`/
 * `auth:client`/`EstablishClientPortalTenantContext`/
 * `ApplyTenantDatabaseContext` stack every other authenticated Client
 * Portal action already has, registered in routes/web.php.
 *
 * Ordinary session auth + CSRF middleware (already applied by the `web`
 * group) is correct and sufficient here — provider-core §6.3's own
 * documented, narrower threat model for the Link-token flow: the
 * public_token never leaves FirmsVault's own authenticated page via a
 * cross-origin redirect, so no `state`/PKCE-shaped defense is needed.
 *
 * FOUND AND FIXED (Checkpoint 7 authorization review, item 19): this
 * controller previously resolved the client-supplied
 * `firm_integration_id` by firm membership ONLY ("belongs to the same
 * firm"), never by matter/request ownership — a real IDOR letting a
 * client with legitimate access to any matter in the firm complete a
 * DIFFERENT matter's connection with their own public_token. Fixed by
 * resolving the connection from `FinancialEvidenceMatterRequest.firm_integration_id`
 * — the server-authoritative binding `PlaidAccountSelectionPage::mount()`
 * persists at the moment it creates the connection FOR this specific
 * request, before the client ever sees an id at all. The
 * client-supplied `firm_integration_id` is still validated as present
 * (wire-format compatibility with the existing Link-flow JS) but is now
 * cross-checked against, never substituted for, the server's own value.
 *
 * FOUND AND FIXED (Checkpoint 7, surfaced by a full-suite-only flake in
 * this controller's own new regression test): `completeLinkTokenConnection()`
 * requires a real `users.id` — `requested_by_firm_user_id` is a
 * `firm_users.id` (the column name says exactly what it is). Passing it
 * straight through made `ProviderConnectionService::resolveActingFirmUser()`
 * throw for any row where the two independent id sequences don't
 * coincidentally match, i.e. the overwhelming majority of rows in any
 * real database — see `PlaidAccountSelectionPage`'s own docblock for
 * the full analysis (the identical defect existed at two more call
 * sites there). Fixed by resolving `requestedBy->user_id`.
 */
class PlaidExchangeController extends Controller
{
    public function exchange(Request $request): JsonResponse
    {
        /** @var ClientPortalUser|null $portalUser */
        $portalUser = Auth::guard('client')->user();

        if ($portalUser === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'public_token' => ['required', 'string'],
            'firm_integration_id' => ['required', 'integer'],
            'matter_id' => ['required', 'integer'],
        ]);

        $matter = Matter::query()->find((int) $validated['matter_id']);

        if ($matter === null || ! app(ClientPortalMatterAccessPolicyService::class)->canAccessMatter($portalUser, $matter)) {
            return response()->json(['message' => 'Not authorized for this matter.'], 403);
        }

        $request2 = FinancialEvidenceMatterRequest::query()
            ->where('matter_id', $matter->id)
            ->where('status', 'pending')
            ->latest('requested_at')
            ->first();

        if ($request2 === null) {
            return response()->json(['message' => 'No pending request for this matter.'], 404);
        }

        if ($request2->firm_integration_id === null || $request2->firm_integration_id !== (int) $validated['firm_integration_id']) {
            // The client-supplied id must match the server's own
            // record of which connection was created FOR this request
            // — never trusted on its own. A mismatch means either a
            // stale client (the request moved on since the id was
            // issued) or a tampered id aimed at a different matter's
            // connection; both are correctly rejected the same way.
            return response()->json(['message' => 'This connection does not belong to the current request.'], 403);
        }

        try {
            $connection = FirmIntegration::query()
                ->where('id', $request2->firm_integration_id)
                ->where('firm_id', $matter->firm_id)
                ->firstOrFail();

            $result = app(ProviderConnectionService::class)->completeLinkTokenConnection(
                $connection,
                $validated['public_token'],
                $request2->requestedBy->user_id,
            );

            if (! $result->successful) {
                return response()->json(['message' => $result->errorMessage ?? 'Could not complete connection.'], 422);
            }

            $request2->update(['status' => 'reviewed']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            // FOUND AND FIXED (Checkpoint 7 authorization review, item
            // 19): this route is a bare, non-Filament-routed endpoint
            // (registered directly in routes/web.php) — no request ever
            // passes through Filament's own SetUpPanel middleware for
            // it, so Filament::getCurrentPanel() is always null here and
            // getUrl() previously fell back to the DEFAULT panel
            // ('admin'), which has no 'plaid-date-range-confirmation-page'
            // route at all. Every successful exchange — the happy path
            // this whole checkpoint exists for — 500'd on its own
            // success response. Passing the panel explicitly makes URL
            // generation independent of "current panel" state entirely.
            'redirect' => PlaidDateRangeConfirmationPage::getUrl(['matter' => $matter->id], panel: 'client-portal'),
        ]);
    }
}
