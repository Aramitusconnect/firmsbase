<?php

namespace App\Services;

use App\Enums\ClientPortalStatus;
use App\Enums\ConsentChannel;
use App\Models\Client;
use App\Models\ClientPortalUser;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * ClientPortalService — portal status/invitation lifecycle only. No
 * actual invitation email/SMS is sent here (gated on Phase 4
 * deliverability infrastructure). invite() enforces Phase 1's
 * ConsentService: a client cannot be invited to the portal without a
 * granted, unrevoked consent record on the Portal channel — treating
 * the invitation itself as a portal notification the project's consent
 * rule already covers.
 *
 * Checkpoint 4 ("Plaid financial evidence add-on") addition: activate()
 * — the one new, additive method that completes the invitation -> real
 * login credential lifecycle
 * (checkpoint4-design-matter-and-client-portal.md §2.5.1).
 * invite()/acceptInvitation()/disable() bodies below are unmodified.
 */
class ClientPortalService
{
    public function __construct(private ConsentService $consentService) {}

    /**
     * @throws \RuntimeException if portal consent has not been granted
     */
    public function invite(Client $client): Client
    {
        return (new TenantContextService)->runWithFirmContext($client->firm_id, function () use ($client) {
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

        return (new TenantContextService)->runWithFirmContext($client->firm_id, function () use ($client) {
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
        return (new TenantContextService)->runWithFirmContext(
            $client->firm_id,
            fn () => tap($client)->update(['portal_status' => ClientPortalStatus::Disabled])
        );
    }

    /**
     * Checkpoint 4 ("Plaid financial evidence add-on") addition
     * (checkpoint4-design-matter-and-client-portal.md §2.5.1). Completes
     * the invitation -> real login credential lifecycle: calls the
     * existing, unmodified acceptInvitation() (still just flips
     * portal_status/clears the token) and then creates/updates the
     * ClientPortalUser row that actually lets this client log in. This
     * is the ONE behavioral addition to existing code the Client Portal
     * design requires — the consent-gated status/token lifecycle
     * already existed; only the final "create a real login credential"
     * step was missing.
     *
     * updateOrCreate() (rather than create()) makes this method safely
     * re-callable if a client's invitation is ever reissued after a
     * prior activation (e.g. following disable() -> re-invite()) —
     * client_id is a unique FK, so at most one ClientPortalUser row can
     * ever exist per Client regardless.
     *
     * @throws \RuntimeException if the token does not match (via acceptInvitation())
     */
    public function activate(Client $client, string $token, string $password): ClientPortalUser
    {
        $client = $this->acceptInvitation($client, $token);

        return (new TenantContextService)->runWithFirmContext($client->firm_id, function () use ($client, $password) {
            return ClientPortalUser::query()->updateOrCreate(
                ['client_id' => $client->id],
                [
                    'email' => $client->email,
                    'password' => Hash::make($password),
                    'is_active' => true,
                ],
            );
        });
    }
}
