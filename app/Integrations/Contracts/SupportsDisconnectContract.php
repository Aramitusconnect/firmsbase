<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

/**
 * SupportsDisconnectContract — only the remote revocation call is
 * provider-specific. Local teardown (crypto-shredding stored
 * credentials, emitting the audit event, tombstoning connection state)
 * is generic core logic that will live in a future checkpoint's
 * connection service, never in a provider class or in this interface.
 */
interface SupportsDisconnectContract
{
    /**
     * Ask the provider to revoke access for the given connection
     * context (e.g. revoke an OAuth token, deactivate an API key).
     *
     * @param array<string, mixed> $context
     * @return bool whether the provider confirmed revocation.
     */
    public function revokeAtProvider(array $context): bool;
}
