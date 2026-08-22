<?php

declare(strict_types=1);

namespace App\Integrations\Data;

use DateTimeInterface;

/**
 * ConsumedOAuthState — the internal-use result of
 * IntegrationOAuthStateService::resolveAndConsume()'s atomic one-time
 * claim. Carries the now-decrypted PKCE verifier plaintext for the
 * DURATION of the current callback operation only — callers
 * (ProviderConnectionService) must keep $pkceVerifierPlaintext in
 * memory only long enough to complete the code exchange, never log it,
 * persist it, or include it in an exception message. This object is
 * never returned past ProviderConnectionService to any HTTP-layer
 * caller (OAuthCallbackResult is the outward-facing result type).
 */
final class ConsumedOAuthState
{
    public function __construct(
        public readonly int $id,
        public readonly int $firmId,
        public readonly int $firmIntegrationId,
        public readonly int $initiatingUserId,
        public readonly int $initiatingFirmUserId,
        public readonly string $redirectUri,
        public readonly string $pkceVerifierPlaintext,
        public readonly DateTimeInterface $consumedAt,
    ) {}
}
