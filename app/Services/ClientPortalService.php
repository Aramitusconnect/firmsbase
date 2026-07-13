<?php

namespace App\Services;

use App\Enums\ClientPortalStatus;
use App\Enums\ConsentChannel;
use App\Models\Client;
use Illuminate\Support\Str;

/**
 * ClientPortalService — portal status/invitation lifecycle only. No
 * actual invitation email/SMS is sent here (gated on Phase 4
 * deliverability infrastructure). invite() enforces Phase 1's
 * ConsentService: a client cannot be invited to the portal without a
 * granted, unrevoked consent record on the Portal channel — treating
 * the invitation itself as a portal notification the project's consent
 * rule already covers.
 */
class ClientPortalService
{
    public function __construct(private ConsentService $consentService)
    {
    }

    /**
     * @throws \RuntimeException if portal consent has not been granted
     */
    public function invite(Client $client): Client
    {
        return (new TenantContextService())->runWithFirmContext($client->firm_id, function () use ($client) {
            // Section 39A-3L, Checkpoint 11 — moved inside this same
            // runWithFirmContext() wrap: communication_consents is now
            // FORCE-RLS protected, so this isGranted() read must share
            // the write's active context (checking it before the wrap
            // began would always read zero rows once FORCE is active).
            if (! $this->consentService->isGranted($client->firm, $client->id, ConsentChannel::Portal)) {
                throw new \RuntimeException(
                    'Cannot invite client to the portal without a granted, unrevoked portal consent record.'
                );
            }

            $client->update([
                'portal_status' => ClientPortalStatus::Invited,
                'portal_invitation_token' => (string) Str::uuid7(),
                'portal_invitation_sent_at' => now(),
            ]);

            return $client->fresh();
        });
    }

    /**
     * @throws \RuntimeException if the token does not match
     */
    public function acceptInvitation(Client $client, string $token): Client
    {
        if (empty($client->portal_invitation_token) || ! hash_equals($client->portal_invitation_token, $token)) {
            throw new \RuntimeException('Invalid or expired portal invitation token.');
        }

        return (new TenantContextService())->runWithFirmContext($client->firm_id, function () use ($client) {
            $client->update([
                'portal_status' => ClientPortalStatus::Active,
                'portal_invitation_accepted_at' => now(),
                'portal_invitation_token' => null,
            ]);

            return $client->fresh();
        });
    }

    public function disable(Client $client): Client
    {
        return (new TenantContextService())->runWithFirmContext(
            $client->firm_id,
            fn () => tap($client)->update(['portal_status' => ClientPortalStatus::Disabled])
        );
    }
}
