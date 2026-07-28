<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

/**
 * SupportsLinkTokenContract — implemented only by providers whose
 * consent/connect flow is Plaid's Link-token shape: a server-issued,
 * short-lived link_token consumed by a CLIENT-SIDE SDK widget (never a
 * server-to-server redirect/authorization-code exchange the way
 * SupportsOAuthContract's flow is), whose success callback yields a
 * public_token the server then exchanges for a permanent access_token.
 *
 * Per SupportsOAuthContract's own docblock ("providers with a
 * different exchange shape... should not be forced to implement this
 * contract at all, not stretch it") and
 * checkpoint4-pre-construction-inventory.md §6: this is a genuinely
 * different wire shape, not a variant of OAuth2 — there is no
 * authorization-code redirect, no `state`/PKCE CSRF-defeat requirement
 * (the public_token never leaves the browser via a third-party-domain
 * redirect the way an OAuth authorization code does — Plaid Link runs
 * as a widget embedded directly in FirmsVault's own already-authenticated
 * page and its onSuccess callback fires an ordinary same-origin AJAX
 * call, never a full-page cross-origin bounce — see
 * checkpoint4-design-plaid-provider-core.md §5 for the full reasoning),
 * and no refresh-token rotation (a Plaid access_token does not expire
 * on its own; it is invalidated only by /item/remove).
 *
 * FirmsVault Live Integrations, Checkpoint 4
 * (checkpoint4-design-plaid-provider-core.md §2). Placed alongside the
 * other 9 contract files, changing none of them — additive only.
 */
interface SupportsLinkTokenContract
{
    /**
     * Ask the provider to issue a short-lived link_token for the
     * client-side Link SDK to consume. Must not attempt to obtain the
     * eventual access_token itself — this is purely the token-issuance
     * half of the two-phase flow.
     *
     * @param  array<string, mixed>  $context  caller-supplied context.
     *                                         Always carries
     *                                         'connection' (a
     *                                         FirmIntegration). For a
     *                                         NEW Item, carries
     *                                         'requested_capabilities'
     *                                         (string[] of
     *                                         ResourceType values —
     *                                         translated internally
     *                                         into Plaid `products`).
     *                                         For UPDATE MODE
     *                                         re-authentication of an
     *                                         EXISTING Item, carries
     *                                         'update_access_token'
     *                                         (the connection's own
     *                                         already-decrypted
     *                                         access_token plaintext —
     *                                         the caller decrypts it,
     *                                         this method never
     *                                         decrypts credentials
     *                                         itself) INSTEAD OF
     *                                         'requested_capabilities'.
     *                                         Exactly one of the two
     *                                         must be present; a
     *                                         provider implementation
     *                                         must throw on neither/both.
     * @return array<string, mixed> must include 'link_token' and
     *                              'expiration' (ISO 8601 string) —
     *                              encryption/persistence of neither is
     *                              needed (a link_token is not a durable
     *                              secret; it is single-use and
     *                              short-lived by Plaid's own design).
     */
    public function createLinkToken(array $context): array;

    /**
     * Exchange a client-obtained public_token for a permanent
     * access_token + the provider's own persistent connection identity.
     * Only called on a NEW-Item connect — update-mode re-authentication
     * never calls this, since the underlying access_token is unchanged
     * by a successful update-mode Link session.
     *
     * @param  array<string, mixed>  $context  caller-supplied context.
     *                                         Always carries
     *                                         'connection'.
     * @return array<string, mixed> must include 'access_token' and
     *                              'item_id' — encryption and
     *                              persistence are the caller's
     *                              responsibility (mirrors
     *                              SupportsOAuthContract::exchangeCodeForToken()'s
     *                              identical "raw token-set shape,
     *                              caller persists" contract), plus MAY
     *                              include 'institution_id' (Plaid's
     *                              coarser-grained "which bank").
     */
    public function exchangePublicToken(string $publicToken, array $context): array;
}
