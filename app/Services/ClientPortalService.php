<?php

namespace App\Services;

use App\Enums\ClientPortalStatus;
use App\Enums\ConsentChannel;
use App\Models\Client;
use App\Models\ClientPortalUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
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
    /**
     * Mission 3A (MyAttorney Launch-Flow Closure) — how long an
     * invitation's signed link stays cryptographically valid. Mirrors
     * MarketplaceIntakeService::DEFAULT_EXPIRY_DAYS' own precedent of
     * relying on Laravel's own temporarySignedRoute() expiration
     * rather than a separate DB column — a real invitation link that
     * has gone stale must fail closed at the signature-verification
     * layer, before any application code (or the token-based RLS
     * self-lookup policy) ever runs.
     */
    private const INVITATION_LINK_EXPIRY_DAYS = 14;

    public function __construct(private ConsentService $consentService) {}

    /**
     * @throws \RuntimeException if portal consent has not been granted
     */
    public function invite(Client $client): Client
    {
        $updated = (new TenantContextService)->runWithFirmContext($client->firm_id, function () use ($client) {
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

            // Always regenerates a fresh token, even for an
            // already-Invited client — this IS the resend/revoke
            // mechanism: a previously-issued link's own token no
            // longer matches any row once this update() commits, so
            // the clients_portal_invitation_self_lookup RLS policy
            // stops resolving it (identical single-use-token shape to
            // acceptInvitation()'s own token-clearing on success).
            $client->update([
                'portal_status' => ClientPortalStatus::Invited,
                'portal_invitation_token' => (string) Str::uuid7(),
                'portal_invitation_sent_at' => now(),
            ]);

            return $client->fresh();
        });

        // Mission 3A — the ONE behavioral addition to invite() this
        // closure mission makes: an invitation that changes no status/
        // token behavior but, for the first time, actually reaches the
        // client's inbox. Deferred to afterCommit() and never allowed
        // to throw — a transactional email failing to send must never
        // roll back (or appear to fail) the Firm's own already-durably-
        // recorded invite decision, mirroring
        // MarketplaceIntakeService::markAccepted()'s own established
        // pattern.
        DB::afterCommit(function () use ($updated) {
            try {
                app(ClientPortalInvitationNotificationService::class)->notifyInvited($updated, $this->invitationUrl($updated));
            } catch (\Throwable $e) {
                report($e);
            }
        });

        return $updated;
    }

    /**
     * The ONLY identifier ever placed in the public invitation-accept
     * URL — mirrors MarketplaceIntakeService::signedUrl() exactly. The
     * signature carries nothing but the opaque, single-use
     * portal_invitation_token; every other fact about the invitation
     * (which Client, which Firm) is read server-side via the token-
     * scoped RLS self-lookup once the link is opened, never trusted
     * from the URL itself beyond that one token.
     */
    public function invitationUrl(Client $client): string
    {
        if (empty($client->portal_invitation_token)) {
            throw new \RuntimeException('This client has no active portal invitation token.');
        }

        return URL::temporarySignedRoute(
            'client-portal.invitation.accept',
            now()->addDays(self::INVITATION_LINK_EXPIRY_DAYS),
            ['token' => $client->portal_invitation_token],
        );
    }

    /**
     * Resolves a clients row from nothing but its own
     * portal_invitation_token — a genuinely unauthenticated visitor
     * holds no firm context and no numeric Client id. Mirrors
     * MarketplaceIntakeService::resolveByUuid() exactly. Returns null
     * (never throws) for an unknown, expired-and-rotated, or
     * already-consumed token — the RLS policy itself makes those three
     * cases indistinguishable from "not found," which is the intended
     * anti-enumeration behavior.
     */
    public function resolveByInvitationToken(string $token): ?Client
    {
        return (new TenantContextService)->withClientPortalInvitationSelfLookupContext(
            $token,
            fn () => Client::query()->where('portal_invitation_token', $token)->first(),
        );
    }

    /**
     * @throws \RuntimeException if the token does not match, or the
     *                           client is not currently in the Invited state (e.g. the Firm
     *                           has since called disable() on a still-pending invitation —
     *                           a stale link must never be able to reactivate portal access
     *                           the Firm has explicitly revoked)
     */
    public function acceptInvitation(Client $client, string $token): Client
    {
        if (empty($client->portal_invitation_token) || ! hash_equals($client->portal_invitation_token, $token)) {
            throw new \RuntimeException('Invalid or expired portal invitation token.');
        }

        if ($client->portal_status !== ClientPortalStatus::Invited) {
            throw new \RuntimeException('This invitation is no longer active.');
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
