<?php

declare(strict_types=1);

namespace App\Integrations\Data;

use DateTimeInterface;

/**
 * OAuthInitiationResult — returned by
 * IntegrationOAuthStateService::initiate() /
 * ProviderConnectionService::initiateOAuthConnection() to the HTTP
 * layer. Carries only what the controller needs to redirect the
 * browser: the provider-hosted authorization URL (already containing
 * the opaque `state=`/PKCE `code_challenge=` parameters) and the new
 * state row's own (internal) id/expiry for logging. Never carries the
 * raw state value or PKCE verifier as separate fields — the
 * authorizationUrl string is the only place the raw state appears, and
 * it is never persisted anywhere (see IntegrationOAuthState's class
 * docblock).
 */
final class OAuthInitiationResult
{
    public function __construct(
        public readonly string $authorizationUrl,
        public readonly int $oauthStateId,
        public readonly DateTimeInterface $expiresAt,
    ) {}
}
