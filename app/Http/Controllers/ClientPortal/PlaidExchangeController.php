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

        try {
            $connection = FirmIntegration::query()
                ->where('id', (int) $validated['firm_integration_id'])
                ->where('firm_id', $matter->firm_id)
                ->firstOrFail();

            $result = app(ProviderConnectionService::class)->completeLinkTokenConnection(
                $connection,
                $validated['public_token'],
                $request2->requested_by_firm_user_id,
            );

            if (! $result->successful) {
                return response()->json(['message' => $result->errorMessage ?? 'Could not complete connection.'], 422);
            }

            $request2->update(['status' => 'reviewed']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'redirect' => PlaidDateRangeConfirmationPage::getUrl(['matter' => $matter->id]),
        ]);
    }
}
