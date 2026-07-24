<?php

declare(strict_types=1);

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Integrations\Exceptions\AuthorizationCodeAlreadyUsedException;
use App\Integrations\Exceptions\ExpiredAuthorizationCodeException;
use App\Integrations\Exceptions\InvalidPkceVerifierException;
use App\Integrations\Exceptions\OAuthAccountMismatchException;
use App\Integrations\Exceptions\OAuthRedirectUriMismatchException;
use App\Integrations\Exceptions\OAuthStateAlreadyConsumedException;
use App\Integrations\Exceptions\OAuthStateExpiredException;
use App\Integrations\Exceptions\OAuthStateNotFoundException;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\ProviderConnectionService;
use App\Services\TenantContextService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * OAuthConnectionController — the first HTTP controller in the
 * App\Integrations domain (Checkpoint 5,
 * checkpoint-00-final-specification.md §6; confirmed by Agent H's
 * review that no prior controller/route exists anywhere in this
 * domain). Deliberately thin: both actions do only request-shape
 * validation, firm/connection resolution, and delegate every real
 * decision to ProviderConnectionService.
 *
 * Firm resolution bootstrap (both actions): {firmIntegration} is
 * DELIBERATELY NOT an implicit Eloquent route-model-bound parameter.
 * firm_integrations has permanent FORCE ROW LEVEL SECURITY with no
 * self-lookup carve-out of its own (unlike firm_users/
 * integration_oauth_states) — an implicit binding would try to resolve
 * the row before any tenant context exists and would always see zero
 * rows. Instead, this controller resolves the CURRENT user's own single
 * active firm membership first (User::activeFirmUser(), the exact same
 * self-lookup bootstrap App\Http\Middleware\EstablishFirmTenantContext
 * already uses for Filament panel access), then looks up the
 * FirmIntegration WITHIN that firm's own tenant context — mirroring
 * this codebase's one existing precedent for "which firm is this
 * authenticated user currently acting as" rather than inventing a
 * second one.
 *
 * Post-callback redirect destination (frozen-design-post-review.md item
 * 11: no `redirect_intent` column/enum, no request-suppliable
 * destination ever): a SINGLE hardcoded route
 * (`filament.firm.pages.dashboard`) for every outcome — success,
 * scope-insufficient, and every caught failure alike. The frozen
 * design's own illustrative example (`route('firm.integrations.show',
 * $connection)`) assumes a firm-panel integrations detail page that
 * does not exist in this checkpoint's scope (building that UI is
 * explicitly forbidden — see the master directive's "Firm Integrations
 * UI" exclusion) — the firm dashboard route is the one already-existing,
 * always-available, zero-request-suppliable-input destination this
 * checkpoint can safely use instead. A future UI checkpoint replacing
 * this with a real per-connection detail page is expected and is a
 * one-line change confined to buildRedirectResponse() below.
 */
class OAuthConnectionController extends Controller
{
    public function __construct(private readonly ProviderConnectionService $connectionService)
    {
    }

    public function initiate(Request $request, string $firmIntegration): RedirectResponse
    {
        $user = $request->user();

        $firmUser = $user->activeFirmUser();

        if ($firmUser === null) {
            abort(403, 'No active firm membership.');
        }

        $connection = (new TenantContextService())->runWithFirmContext(
            $firmUser->firm_id,
            fn () => FirmIntegration::query()->where('uuid', $firmIntegration)->first()
        );

        if ($connection === null) {
            abort(404);
        }

        try {
            $result = $this->connectionService->initiateOAuthConnection(
                $connection,
                $user->id,
                route('integrations.oauth.callback', [], true),
            );
        } catch (RuntimeException) {
            abort(403, 'You are not permitted to connect this integration.');
        }

        // Provider-hosted, cross-origin by design — never same-origin,
        // so RedirectResponse::away() (skips Laravel's normal
        // same-origin URL generation helpers) is deliberate here, not
        // an oversight.
        return redirect()->away($result->authorizationUrl);
    }

    public function callback(Request $request): RedirectResponse
    {
        $user = $request->user();

        $rawState = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        if ($rawState === '' || $code === '') {
            return $this->buildRedirectResponse('error', 'This connection link is missing required information.');
        }

        try {
            $result = $this->connectionService->completeOAuthCallback($rawState, $code, $user->id);

            if (! $result->successful) {
                return $this->buildRedirectResponse('warning', $result->errorMessage ?? 'The connection completed with warnings.', $result->firmIntegration);
            }

            return $this->buildRedirectResponse('success', 'Integration connected successfully.', $result->firmIntegration);
        } catch (OAuthStateNotFoundException|OAuthStateAlreadyConsumedException|OAuthStateExpiredException $e) {
            return $this->buildRedirectResponse('error', $e->getMessage());
        } catch (OAuthRedirectUriMismatchException|OAuthAccountMismatchException $e) {
            return $this->buildRedirectResponse('error', $e->getMessage());
        } catch (InvalidPkceVerifierException|ExpiredAuthorizationCodeException|AuthorizationCodeAlreadyUsedException $e) {
            return $this->buildRedirectResponse('error', $e->getMessage());
        } catch (SanitizedProviderHttpException) {
            return $this->buildRedirectResponse('error', 'The provider could not complete this request. Please try again.');
        }
    }

    /**
     * Checkpoint 10 retarget (frozen-design-post-security-review.md §11;
     * agent-10h-architecture-security-review.md §11.5): this method's own
     * long-standing docblock explicitly invited this change once a
     * per-connection detail page existed ("A future UI checkpoint
     * replacing this with a real per-connection detail page is
     * expected"). $status/$message are flashed to the session for the
     * firm panel to display (never embedded in the redirect URL's query
     * string itself, so the authorization code and state value —
     * already consumed and never placed in this URL to begin with —
     * cannot linger in browser history via this response either).
     *
     * $connection is OPTIONAL: every success/warning outcome has one
     * (from OAuthCallbackResult::$firmIntegration), but several early
     * failure paths (missing state/code, an unresolvable/expired/
     * already-consumed OAuth state) never reach a resolved connection at
     * all — those still fall back to the firm dashboard, the one
     * always-available, zero-request-suppliable-input destination,
     * exactly as before.
     */
    private function buildRedirectResponse(string $status, string $message, ?FirmIntegration $connection = null): RedirectResponse
    {
        if ($connection !== null) {
            return redirect()
                ->route('filament.firm.resources.firm-integrations.view', ['record' => $connection])
                ->with($status, $message);
        }

        return redirect()
            ->route('filament.firm.pages.dashboard')
            ->with($status, $message);
    }
}
