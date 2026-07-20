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
 */
enum CredentialType: string
{
    case OauthAccessToken = 'oauth_access_token';
    case OauthRefreshToken = 'oauth_refresh_token';
    case ApiKey = 'api_key';
    case WebhookSigningSecret = 'webhook_signing_secret';
}
