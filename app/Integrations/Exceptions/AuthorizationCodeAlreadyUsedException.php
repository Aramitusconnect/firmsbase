<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use RuntimeException;

/**
 * AuthorizationCodeAlreadyUsedException — thrown by
 * TestProvider::exchangeCodeForToken() when the supplied authorization
 * code has already been marked used in its private static in-process
 * simulation registry (replay prevention — see TestProvider's class
 * docblock, and Agent H's review item 11 for the three conditions this
 * mechanism is approved under). Mirrors a real provider's own
 * single-use authorization-code enforcement.
 */
final class AuthorizationCodeAlreadyUsedException extends RuntimeException
{
    public function __construct(string $message = 'The authorization code has already been used.')
    {
        parent::__construct($message);
    }
}
