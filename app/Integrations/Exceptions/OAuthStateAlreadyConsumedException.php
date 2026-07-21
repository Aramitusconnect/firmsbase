<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use RuntimeException;

/**
 * OAuthStateAlreadyConsumedException — thrown by
 * IntegrationOAuthStateService::resolveAndConsume() when the atomic
 * one-time-claim UPDATE affects zero rows because consumed_at was
 * already set (a genuine replay, or the losing side of a two-attempt
 * concurrent claim race — see the atomic UPDATE ... WHERE consumed_at
 * IS NULL ... RETURNING * shape in that method). Distinguished from
 * OAuthStateExpiredException by a diagnostic follow-up read AFTER the
 * failed claim (already scoped to a specific row id the caller already
 * legitimately resolved in Step-A — not an enumeration risk), purely so
 * the correct `integration_oauth.state_replay_rejected` audit event
 * type can be recorded.
 */
final class OAuthStateAlreadyConsumedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This OAuth state has already been consumed and cannot be used again.');
    }
}
