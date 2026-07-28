<?php

declare(strict_types=1);

namespace App\Integrations\Enums;

/**
 * CredentialType — the kind of secret material an `integration_credentials`
 * row holds (Checkpoint 4, checkpoint-00-final-specification.md §5/§10;
 * frozen-design-post-review.md's "Final schema" section).
 *
 * `WebhookSigningSecret` exists ONLY as a descriptive label reserved for
 * future Checkpoint 7 use (the inert `webhook_routing_token` column and
 * its partial unique index on `integration_credentials`, frozen per
 * checkpoint-00-final-specification.md §11 R4 finding item 8) — no
 * Checkpoint 4 code path ever creates a row with this type, and no code
 * in this checkpoint treats it specially. It is never consulted by any
 * RLS predicate.
 *
 * FirmsVault Live Integrations, Checkpoint 4 addition
 * (checkpoint4-design-plaid-provider-core.md §13;
 * checkpoint4-combined-design.md §1.1.2, binding): `ProviderAccessToken` —
 * a new, semantically-distinct case for Plaid's Item `access_token`,
 * deliberately NOT stored under `OauthAccessToken`. Plaid's
 * `access_token` is not obtained via an OAuth2 exchange, has no paired
 * refresh token, and never expires on its own (invalidated only by
 * `/item/remove`) — none of `OauthAccessToken`'s implied semantics
 * ("paired with `OauthRefreshToken`, obtained via
 * `exchangeCodeForToken()`") actually hold for it. Reusing
 * `OauthAccessToken` anyway would bake a permanent inaccuracy into the
 * schema and every audit trail that reads `credential_type`. This case
 * requires `App\Jobs\PullSyncJob::runBatchLoop()`'s credential-liveness
 * safety net (`$hasProvisionedOauthCredential`) to also check for it
 * alongside `OauthAccessToken`/`OauthRefreshToken` — see that job's own
 * inline comment for the corresponding widening.
 */
enum CredentialType: string
{
    case OauthAccessToken = 'oauth_access_token';
    case OauthRefreshToken = 'oauth_refresh_token';
    case ApiKey = 'api_key';
    case WebhookSigningSecret = 'webhook_signing_secret';
    case ProviderAccessToken = 'provider_access_token';
}
