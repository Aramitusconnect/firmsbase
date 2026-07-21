<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use RuntimeException;

/**
 * OAuthStateNotFoundException — thrown by
 * IntegrationOAuthStateService::resolveAndConsume() when the Step-A
 * bootstrap lookup (opaque_token_hash match, narrowed further by the
 * integration_oauth_states_self_lookup RLS policy to the currently
 * authenticated caller's own rows) finds no matching row at all. The
 * message is deliberately generic — it never discloses whether a state
 * value never existed, belonged to a different user, or was already
 * garbage-collected, so a caller cannot use this exception's presence
 * to enumerate valid-looking state values.
 */
final class OAuthStateNotFoundException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No matching, unconsumed OAuth state was found for this callback.');
    }
}
