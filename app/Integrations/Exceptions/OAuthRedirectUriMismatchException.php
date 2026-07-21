<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use RuntimeException;

/**
 * OAuthRedirectUriMismatchException — thrown by
 * ProviderConnectionService::completeOAuthCallback() when the claimed
 * integration_oauth_states row's stored redirect_uri does not
 * byte-for-byte match (via
 * App\Integrations\Support\ProviderRedirectUrlValidator::matchesExpected(),
 * hash_equals()-based) the freshly-recomputed expected callback URL for
 * this request. Never includes either URL value in the message — an
 * attacker-supplied redirect_uri is exactly the kind of value that must
 * never be echoed back into a log/exception surface.
 */
final class OAuthRedirectUriMismatchException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The redirect URI for this OAuth callback does not match the expected value.');
    }
}
