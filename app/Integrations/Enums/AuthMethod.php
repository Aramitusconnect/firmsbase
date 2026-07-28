<?php

declare(strict_types=1);

namespace App\Integrations\Enums;

/**
 * AuthMethod — the closed set of authentication mechanisms a provider
 * may declare via IntegrationProviderContract::supportedAuthMethods().
 * Purely descriptive/documentation-level at Checkpoint 1: no OAuth
 * route, credential table, or live provider exists yet
 * (checkpoint-00-final-specification.md §21).
 *
 * FirmsVault Live Integrations, Checkpoint 4 addition
 * (checkpoint4-design-plaid-provider-core.md §1;
 * checkpoint4-combined-design.md §6.1): `LinkToken` — Plaid's
 * server-issued, short-lived `link_token` consumed by the client-side
 * Plaid Link SDK, distinct from a redirect-based OAuth2 authorization
 * code AND from a firm-entered static API key. See
 * `App\Integrations\Contracts\SupportsLinkTokenContract`'s own docblock
 * for the full reasoning why this is a genuinely different exchange
 * shape, not a variant of either existing case.
 */
enum AuthMethod: string
{
    case OAuth2 = 'oauth2';
    case ApiKey = 'api_key';
    case None = 'none';
    case LinkToken = 'link_token';
}
