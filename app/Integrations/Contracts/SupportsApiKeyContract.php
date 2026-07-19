<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

/**
 * SupportsApiKeyContract — implemented only by providers whose auth
 * model is a static API key / merchant key rather than redirect-based
 * OAuth. No credential storage exists at Checkpoint 1
 * (checkpoint-00-final-specification.md §21) — interface shape only.
 */
interface SupportsApiKeyContract
{
    /**
     * @return string[] the credential field names this provider
     *                   requires (e.g. ['api_key'] or
     *                   ['client_id', 'client_secret']) — used to
     *                   drive a future connection-form UI, not to
     *                   store anything itself.
     */
    public function requiredCredentialFields(): array;

    /**
     * Validate the shape/presence of supplied credentials. This is a
     * structural/format check only — it must never make a real network
     * call to verify the credentials actually work against the
     * provider (that responsibility belongs to a later checkpoint's
     * connection-verification flow, not this contract).
     *
     * @param array<string, mixed> $credentials
     */
    public function validateCredentials(array $credentials): bool;
}
