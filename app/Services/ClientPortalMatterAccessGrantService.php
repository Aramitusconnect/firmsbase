<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\TenantIsolationException;
use App\Models\Client;
use App\Models\ClientPortalMatterGrant;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;

/**
 * ClientPortalMatterAccessGrantService — Mission 4 (Client Portal
 * Activation), finding 4.1. `client_portal_matter_grants` (Checkpoint 4
 * — see ClientPortalMatterGrant's own docblock) had zero production
 * writers: the table, its FORCE-RLS policy, and its two read-side
 * consumers (ClientPortalMatterAccessPolicyService,
 * PlaidRequestReviewPage) all existed, but nothing in the Firm panel
 * could ever actually create or revoke a grant — a firm user had no way
 * to give a client visibility into a matter inside the Client Portal.
 * This service is the sole production writer for that lifecycle.
 *
 * grant() uses updateOrCreate() keyed on the partial-unique-index pair
 * (client_id, matter_id) — mirroring ClientPortalService::invite()'s own
 * updateOrCreate() idiom (always re-stamps granted_at/granted_by and
 * clears revoked_at, whether this is a brand new grant or re-opening a
 * previously-revoked one for the same client/matter pair; the partial
 * unique index only ever allows one row with revoked_at IS NULL per
 * pair, so this is always safe).
 *
 * revoke() is a plain, explicit `update(['revoked_at' => now()])` — the
 * row is never deleted, preserving grant history exactly as
 * ClientPortalMatterGrant's own docblock documents (mirrors
 * MatterAssignment.removed_at's established convention).
 *
 * Both methods assert the same-firm, same-client invariant before
 * writing (mirrors TenantSafeTrustPolicyService::assertMatterMatchesLedger()'s
 * throwing style) — a grant naming a matter that does not belong to
 * both the given firm AND the given client must never be created.
 */
class ClientPortalMatterAccessGrantService
{
    public function grant(Firm $firm, Client $client, Matter $matter, FirmUser $grantedBy): ClientPortalMatterGrant
    {
        $this->assertMatterBelongsToFirmAndClient($firm, $client, $matter);

        return (new TenantContextService)->runWithFirmContext($firm->id, function () use ($firm, $client, $matter, $grantedBy) {
            return ClientPortalMatterGrant::query()->updateOrCreate(
                [
                    'client_id' => $client->id,
                    'matter_id' => $matter->id,
                ],
                [
                    'firm_id' => $firm->id,
                    'granted_by' => $grantedBy->user_id,
                    'granted_at' => now(),
                    'revoked_at' => null,
                ],
            );
        });
    }

    public function revoke(Firm $firm, ClientPortalMatterGrant $grant, FirmUser $revokedBy): ClientPortalMatterGrant
    {
        if ($grant->firm_id !== $firm->id) {
            throw new TenantIsolationException(
                "ClientPortalMatterGrant [id={$grant->id}] does not belong to firm [id={$firm->id}]."
            );
        }

        return (new TenantContextService)->runWithFirmContext($firm->id, function () use ($grant) {
            $grant->update(['revoked_at' => now()]);

            return $grant->fresh();
        });
    }

    /**
     * The same-firm, same-client invariant — mirrors
     * TenantSafeTrustPolicyService::assertMatterMatchesLedger()'s
     * throwing style exactly (app/Services/TenantSafeTrustPolicyService.php).
     * A grant must never be created naming a matter that belongs to a
     * different firm, or to a different client, than the ones the
     * caller believes it is granting for.
     */
    private function assertMatterBelongsToFirmAndClient(Firm $firm, Client $client, Matter $matter): void
    {
        if ($matter->firm_id !== $firm->id) {
            throw new TenantIsolationException(
                "Matter [id={$matter->id}] does not belong to firm [id={$firm->id}]."
            );
        }

        if ($matter->client_id !== $client->id) {
            throw new TenantIsolationException(
                "Matter [id={$matter->id}] does not belong to client [id={$client->id}]."
            );
        }
    }
}
