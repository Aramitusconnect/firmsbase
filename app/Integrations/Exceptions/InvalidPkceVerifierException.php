<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use RuntimeException;

/**
 * InvalidPkceVerifierException — thrown when a PKCE code_verifier fails
 * App\Integrations\Support\PkceService::verify() against the
 * code_challenge originally issued for an authorization code. Thrown by
 * TestProvider::exchangeCodeForToken() (the only place in this
 * checkpoint that performs this check, simulating what a real
 * provider's own token endpoint does) and treated as an already-safe,
 * app-defined exception type by OutboundProviderHttpClient — it is
 * never sanitized/rewrapped, since it never carries raw provider
 * response detail.
 */
final class InvalidPkceVerifierException extends RuntimeException
{
    public function __construct(string $message = 'The PKCE verifier does not match the original code_challenge.')
    {
        parent::__construct($message);
    }
}
