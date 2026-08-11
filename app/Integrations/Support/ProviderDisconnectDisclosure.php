<?php

declare(strict_types=1);

namespace App\Integrations\Support;

use App\Integrations\Enums\ProviderKey;

/**
 * ProviderDisconnectDisclosure — Mission 1C (Security Validation,
 * Activation & Staging Proof), section 18. Mission 1B's own
 * `Microsoft365Provider` docblock already correctly researched and
 * documented WHY Microsoft 365 has no `SupportsDisconnectContract`
 * implementation (Microsoft Graph has no self-service "revoke my own
 * app's grant" endpoint for a delegated OAuth2 app — the nearest
 * capability, `revokeSignInSessions`, requires admin-level Graph
 * permissions this app registration deliberately does not hold, and
 * revokes every session for every app the user has ever signed into,
 * a disproportionate side effect for "disconnect this one
 * integration"). That docblock explicitly named the remaining gap:
 * "The disconnect-confirmation UI should disclose that Microsoft's
 * own record of the grant persists... a UX/UI concern... not
 * something this class's code can close." This class closes it — the
 * one concrete, addressable piece of that finding, surfaced to the
 * user at the exact moment they'd need to act on it (disconnect
 * time), rather than silently implying full revocation occurred.
 *
 * `ProviderConnectionService::disconnect()`'s own local teardown
 * (credential crypto-erasure, webhook-routing-token clearing, status
 * transition, and the `integration_oauth.provider_revocation_not_supported`
 * audit event) is unaffected by and unrelated to this — this class is
 * UI copy only, never security logic.
 */
class ProviderDisconnectDisclosure
{
    /**
     * Null for any provider whose SupportsDisconnectContract
     * implementation actually revokes at the provider (or for a
     * provider not yet known to have this limitation) — callers
     * should append this to their own base disconnect copy only when
     * non-null, never replace it.
     */
    public static function forProvider(?ProviderKey $providerKey): ?string
    {
        return match ($providerKey) {
            ProviderKey::Microsoft365 => 'Microsoft 365 does not support remote revocation through this app: only your locally stored credentials and webhook routing are removed here. Microsoft\'s own record of this app\'s access grant persists until you or your Microsoft 365 administrator separately remove it via myaccount.microsoft.com ("Apps and services") or the Entra admin center.',
            default => null,
        };
    }
}
