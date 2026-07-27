<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use RuntimeException;

/**
 * OAuthTenantMismatchException — thrown by
 * ProviderConnectionService::finishCallback() when a reauthorization
 * callback's returned tenant identifier does not match (via
 * hash_equals()) the tenant already pinned on the target
 * firm_integrations row at first connect.
 *
 * FirmsVault Live Integrations, Checkpoint 2 addition
 * (checkpoint2-combined-design.md §1, §2 P-6d; checkpoint2-security-review.md
 * Finding 1): mirrors OAuthAccountMismatchException's exact shape and
 * discipline, applied to `external_tenant_id` instead of
 * `external_account_id` — a distinct, coarser-grained concept (the
 * whole connected organization/tenant, vs. the specific connected
 * user account). Kept as its own exception class, rather than reusing
 * OAuthAccountMismatchException, so this class's docblock/message
 * accurately describes what mismatched — never overload an
 * "account"-worded exception to also mean "tenant". Never includes
 * either raw identifier in the message (per this codebase's "full
 * email/identifier address: never in raw form, never side-by-side"
 * audit rule) — no credential is written and the connection is pushed
 * to Error before this is thrown.
 */
final class OAuthTenantMismatchException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'The re-authorized provider tenant does not match the originally connected tenant for this integration.'
        );
    }
}
