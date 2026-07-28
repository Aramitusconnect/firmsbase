<?php

declare(strict_types=1);

namespace App\Integrations\Data;

/**
 * LinkTokenInitiationResult — returned by
 * ProviderConnectionService::initiateLinkTokenConnection()/
 * initiateLinkTokenUpdateMode() to the HTTP/Livewire layer. Mirrors
 * OAuthInitiationResult's shape but narrower — no oauthStateId, since
 * Plaid's Link-token flow persists no server-side correlation row
 * between "issue link_token" and "receive public_token" (see
 * SupportsLinkTokenContract's own docblock for why no such row is
 * needed: the public_token never leaves FirmsVault's own authenticated
 * page via a cross-origin redirect, so there is no untrusted round-trip
 * requiring a durable state token to survive).
 *
 * FirmsVault Live Integrations, Checkpoint 4
 * (checkpoint4-design-plaid-provider-core.md §5.1).
 */
final class LinkTokenInitiationResult
{
    public function __construct(
        public readonly string $linkToken,
        public readonly string $expiration,
    ) {}
}
