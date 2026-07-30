<?php

declare(strict_types=1);

namespace App\Integrations\Billing;

use App\Integrations\Enums\ProviderOperationClaimDecision;
use App\Integrations\Models\ProviderOperationAttempt;

/**
 * ProviderOperationClaim — `ProviderOperationAttemptService::claim()`'s
 * return value (Checkpoint 8.2 §A4/§A5). Carries the gate decision, the
 * durable attempt row it was derived from, and — only when the decision
 * is `Proceed` — the owner token that every subsequent transition on
 * that row must present.
 *
 * The owner token is what makes a zombie worker harmless: if this
 * worker's lease is later taken over, its token no longer matches and
 * every write it attempts fails closed instead of overwriting the new
 * owner's state.
 */
final class ProviderOperationClaim
{
    public function __construct(
        public readonly ProviderOperationClaimDecision $decision,
        public readonly ProviderOperationAttempt $attempt,
        public readonly ?string $ownerToken,
    ) {}

    /** True only when this worker may issue the outbound provider request. */
    public function maySendProviderRequest(): bool
    {
        return $this->decision->maySendProviderRequest();
    }

    /**
     * The owner token, guaranteed non-null. Call only on a `Proceed`
     * claim — every other decision denies the send, so asking for a
     * token there is a programming error rather than a runtime
     * condition.
     */
    public function ownerTokenOrFail(): string
    {
        if ($this->ownerToken === null) {
            throw new \LogicException(
                'No owner token exists for a "'.$this->decision->value.'" claim — only a Proceed claim owns the send.'
            );
        }

        return $this->ownerToken;
    }
}
