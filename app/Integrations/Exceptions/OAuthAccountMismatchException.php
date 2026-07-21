<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use RuntimeException;

/**
 * OAuthAccountMismatchException — thrown by
 * ProviderConnectionService::completeOAuthCallback() when a
 * reauthorization callback's returned external_account_id does not
 * match (via hash_equals()) the account already pinned on the target
 * firm_integrations row at first connect. Never includes either raw
 * identifier in the message (per this codebase's "full email/identifier
 * address: never in raw form, never side-by-side" audit rule) — no
 * credential is written and the connection is pushed to Error before
 * this is thrown.
 */
final class OAuthAccountMismatchException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'The re-authorized provider account does not match the originally connected account for this integration.'
        );
    }
}
