<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use RuntimeException;

/**
 * OAuthStateExpiredException — thrown by
 * IntegrationOAuthStateService::resolveAndConsume() when the atomic
 * claim UPDATE's own `WHERE expires_at > now()` clause is what caused
 * zero rows to be affected (the row exists and is unconsumed, but its
 * 10-minute default / 30-minute hard-ceiling TTL has passed). See
 * OAuthStateAlreadyConsumedException's docblock for why this is
 * distinguished via a diagnostic follow-up read rather than being
 * folded into a single generic exception.
 */
final class OAuthStateExpiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This OAuth state has expired. Please restart the connection flow.');
    }
}
