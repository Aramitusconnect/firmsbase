<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use RuntimeException;

/**
 * ProviderKillSwitchActiveException — thrown by
 * `App\Integrations\Billing\ProviderOperationPolicyResolver::resolve()`
 * (pipeline step 7, checkpoint4-design-cost-control.md §2/§4.3) the
 * moment any PLATFORM-scope `provider_kill_switches` row matches
 * (product, endpoint-category, or operation level, checked broad to
 * narrow) with `suspended = true`. No further pipeline step runs after
 * this throws — the cheapest possible failure point after entitlement/
 * capability, deliberately before any cache/dedup/cooldown/limit/
 * reservation work is attempted.
 *
 * Deliberately distinct from `ProviderOptionalOperationSuspendedException`
 * — this exception represents a PLATFORM-admin-authored incident-
 * response suspension (never firm-writable); the firm-editable
 * "opt this firm's own optional operation out" mechanism is a
 * structurally different, firm-owned decision — see that exception's
 * own docblock for why they are kept separate rather than sharing one
 * throw type.
 */
final class ProviderKillSwitchActiveException extends RuntimeException
{
    public function __construct(
        public readonly string $providerKey,
        public readonly string $level,
        public readonly string $target,
        ?string $reason = null,
    ) {
        $message = "Provider kill switch active: provider_key={$providerKey}, level={$level}, target={$target}";

        if ($reason !== null && $reason !== '') {
            $message .= ", reason={$reason}";
        }

        parent::__construct($message);
    }
}
