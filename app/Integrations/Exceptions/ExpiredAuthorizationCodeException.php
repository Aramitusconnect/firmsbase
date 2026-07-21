<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use RuntimeException;

/**
 * ExpiredAuthorizationCodeException — thrown by
 * TestProvider::exchangeCodeForToken() when the supplied authorization
 * code is unknown to, or has expired within, its private static
 * in-process simulation registry (see TestProvider's class docblock for
 * the disclosed, narrow, test-only nature of that registry). Mirrors
 * what a real provider's token endpoint would report for an
 * unrecognized/expired `code` parameter — never a raw provider response
 * body, since none was ever made.
 */
final class ExpiredAuthorizationCodeException extends RuntimeException
{
    public function __construct(string $message = 'The authorization code is unknown or has expired.')
    {
        parent::__construct($message);
    }
}
