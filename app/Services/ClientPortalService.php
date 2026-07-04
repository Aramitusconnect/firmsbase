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
    }

    /**
     * @throws \RuntimeException if the token does not match
     */
    public function acceptInvitation(Client $client, string $token): Client
    {
        if (empty($client->portal_invitation_token) || ! hash_equals($client->portal_invitation_token, $token)) {
            throw new \RuntimeException('Invalid or expired portal invitation token.');
        }

        $client->update([
            'portal_status' => ClientPortalStatus::Active,
            'portal_invitation_accepted_at' => now(),
            'portal_invitation_token' => null,
        ]);

        return $client->fresh();
    }

    public function disable(Client $client): Client
    {
        return tap($client)->update(['portal_status' => ClientPortalStatus::Disabled]);
    }
}
