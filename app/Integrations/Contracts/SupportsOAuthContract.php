<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

/**
 * SupportsOAuthContract — implemented only by providers whose auth
 * model is redirect-based OAuth2. Per provider-contracts.md, providers
 * with a different exchange shape (e.g. a Link-token flow) should not
 * be forced to implement this contract at all, not stretch it.
 *
 * No credential storage, OAuth route, or callback handling exists at
 * Checkpoint 1 (checkpoint-00-final-specification.md §21) — this is
 * the interface shape only. Checkpoint 5 owns the real OAuth-state
 * lifecycle that will call these methods.
 *
 * Checkpoint 5 amendment: refreshToken() gains an optional
 * `array $context = []` parameter — a deliberate, backward-compatible
 * widening (Agent H review item 22 / frozen-design-post-review.md
 * §3): every existing call site (there were none in production code
 * before this checkpoint; TestProvider is the only implementer) keeps
 * working unchanged, since the new parameter defaults to an empty
 * array. Added so a caller (ProviderConnectionService) can pass
 * caller-side context (e.g. firm_integration_id, for a provider that
 * needs it to route a refresh call) without a second, parallel method.
 */
interface SupportsOAuthContract
{
    /**
     * Build the provider-hosted authorization URL a firm user is
     * redirected to. Must not perform any network call itself — pure
     * URL construction from the given params (e.g. client id, redirect
     * uri, state, scope, PKCE challenge).
     *
     * @param array<string, mixed> $params
     */
    public function authorizationUrl(array $params): string;

    /**
     * Exchange an authorization code for a token set.
     *
     * @param array<string, mixed> $context caller-supplied context
     *                                       (e.g. redirect uri, PKCE
     *                                       verifier) needed to
     *                                       complete the exchange.
     * @return array<string, mixed> raw token-set shape (access token,
     *                               refresh token, expiry, granted
     *                               scopes, etc.) — encryption and
     *                               persistence are the caller's
     *                               responsibility, not this method's.
     */
    public function exchangeCodeForToken(string $code, array $context): array;

    /**
     * Exchange a refresh token for a new token set.
     *
     * @param  array<string, mixed> $context caller-supplied context
     *                                       (e.g. firm_integration_id).
     *                                       Optional — defaults to an
     *                                       empty array so existing
     *                                       call sites/implementers
     *                                       are unaffected.
     * @return array<string, mixed> raw token-set shape, same contract
     *                               as exchangeCodeForToken().
     */
    public function refreshToken(string $refreshToken, array $context = []): array;

    /**
     * @return string[] the OAuth scopes this provider requires.
     *                   Documentation-only at this layer — never
     *                   treated as authoritative for what was actually
     *                   granted (the granted-scope value returned by
     *                   the provider itself is authoritative).
     */
    public function requiredScopes(): array;
}
