<?php

namespace App\Http\Middleware;

use App\Models\Client;
use App\Models\ClientPortalUser;
use App\Services\TenantContextService;
use Closure;
use Illuminate\Http\Request;

/**
 * EstablishClientPortalTenantContext — Checkpoint 4 ("Plaid financial
 * evidence add-on"), Client Portal authentication foundation. Mirrors
 * `EstablishFirmTenantContext` in shape and discipline, resolving firm
 * context via a ONE-HOP RLS self-lookup bootstrap
 * (checkpoint4-combined-design.md §2.4/§5;
 * checkpoint4-design-matter-and-client-portal.md §2.4).
 *
 * CORRECTED DESIGN: an earlier draft of this checkpoint used a TWO-HOP
 * bootstrap (ClientPortalUser self-lookup, then Client self-lookup),
 * because `client_portal_users` originally carried FORCE ROW LEVEL
 * SECURITY. That design is a confirmed defect (see
 * ClientPortalAuthenticationTest's own docblock for the full empirical
 * reproduction: neither of that table's two policies permitted
 * `Auth::attempt()`/password-reset's `retrieveByCredentials()` to find
 * a row BY EMAIL with no context at all, which is the unavoidable first
 * step of any login). `client_portal_users` has since been reclassified
 * System — identical treatment to `users` — and carries no RLS at all;
 * see that table's own create-migration docblock's "WHY THIS TABLE HAS
 * NO RLS" section. `clients`, however, remains BelongsToTenant +
 * FORCE-RLS protected, and is EQUALLY invisible with no firm context
 * active yet — so exactly one genuine RLS hop is still required:
 *
 * Hop (the only one): `TenantContextService::withClientSelfLookupContext()`
 * sets `app.current_client_id` from the authenticated ClientPortalUser's
 * own `client_id` column — read via an ORDINARY, unwrapped query
 * against `client_portal_users` (no context-wrapping needed, since that
 * table has no RLS to satisfy), never from any request input, query
 * string, or header. This is what makes `client_id` never
 * attacker-influenced anywhere in this chain, directly answering the
 * exact concern judgment call §2.7.d of the source design doc raised.
 * The `clients_self_lookup` policy reads `app.current_client_id` to
 * make exactly this one `Client` row visible; its `firm_id` is then
 * activated as the request's ordinary firm tenant context.
 *
 * The narrow session variable is cleared in its own `finally` block the
 * instant its one bootstrap read completes — by the time $next($request)
 * runs and the rest of the request executes under ordinary
 * app.current_firm_id firm context, the self-lookup variable is no
 * longer set.
 *
 * A portal user with no resolvable client (should not happen under
 * normal operation, since client_id is a NOT NULL unique FK) is left
 * with no context set at all (fail-closed), exactly like
 * EstablishFirmTenantContext's own "no active FirmUser membership"
 * case — ClientPortalUser::canAccessPanel() is the actual gate that
 * keeps an invalid session off the Client Portal panel in the first
 * place.
 *
 * Always clears context in a finally block, even if the next handler
 * throws, so no firm's (or client's) context can ever leak into a
 * later request sharing the same worker process.
 */
class EstablishClientPortalTenantContext
{
    public function __construct(private readonly TenantContextService $tenantContextService) {}

    public function handle(Request $request, Closure $next): mixed
    {
        /** @var ClientPortalUser|null $portalUser */
        $portalUser = $request->user('client'); // explicit guard name — never the default guard

        if ($portalUser === null) {
            return $next($request);
        }

        $portalUserId = (int) $portalUser->getAuthIdentifier();

        // client_portal_users carries no RLS (System classification,
        // identical to users) — an ordinary, unwrapped query, no
        // context-wrapping needed.
        $clientId = ClientPortalUser::query()->findOrFail($portalUserId)->client_id;

        return $this->tenantContextService->withClientSelfLookupContext(
            $clientId,
            function () use ($clientId, $next, $request) {
                // Hop resolved: clients_self_lookup now permits exactly
                // this one SELECT, scoped strictly to the client_id
                // just read off the portal user's own row — never from
                // any request input, query string, or header.
                $client = Client::query()->findOrFail($clientId);

                $this->tenantContextService->setFirmContext($client->firm_id);

                try {
                    return $next($request);
                } finally {
                    $this->tenantContextService->clearFirmContext();
                }
            },
        );
    }
}
