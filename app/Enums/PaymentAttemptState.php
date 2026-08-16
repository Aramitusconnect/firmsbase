<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * PaymentAttemptState — FirmsVault Pay Gate A2. The MINIMAL
 * provider-neutral attempt state model mandated by Master Execution
 * Prompt v1.4 §22. Exactly the seven authorized cases and no more:
 * AUTHORIZED, REQUIRES_ACTION, PROCESSOR_ACCEPTED, VOIDED and EXPIRED
 * are deliberately absent because no current repository behavior
 * requires them, and POC #1 is capture-only.
 *
 * OutcomeUnknown is NOT a failure (v1.4 §23). It means the economic
 * outcome is genuinely undetermined — the money may or may not have
 * moved. It is terminal for automated processing: nothing may
 * automatically progress out of it, and in particular nothing may
 * create a second charge. Resolution comes only from provider-side
 * recovery/reconciliation, which resolves THIS attempt rather than
 * creating a new one.
 */
enum PaymentAttemptState: string
{
    case Created = 'created';
    case Submitted = 'submitted';
    case Captured = 'captured';
    case Declined = 'declined';
    case Failed = 'failed';
    case OutcomeUnknown = 'outcome_unknown';
    case Cancelled = 'cancelled';

    /**
     * The authoritative transition matrix (v1.4 §22 "explicit
     * transition matrix"). Enforced by
     * App\Services\Pay\PaymentAttemptService::transition(); any
     * transition absent from this map is refused.
     *
     * Rationale for each terminal choice:
     *   Captured  — economic success; a reversal is a Refund, never a
     *               backwards attempt transition.
     *   Declined  — provider positively refused; no money moved.
     *   Failed    — local/transport failure BEFORE the provider could
     *               have acted on it.
     *   Cancelled — abandoned before submission only.
     *   OutcomeUnknown — see the class docblock. Deliberately has NO
     *               outgoing automated transitions.
     *
     * @return array<string, list<string>>
     */
    public static function transitionMatrix(): array
    {
        return [
            self::Created->value => [
                self::Submitted->value,
                self::Cancelled->value,
                // A failure that happens before anything is sent (e.g.
                // the command could not be built) never reached the
                // provider, so it is Failed, not Unknown.
                self::Failed->value,
            ],
            self::Submitted->value => [
                self::Captured->value,
                self::Declined->value,
                self::Failed->value,
                self::OutcomeUnknown->value,
            ],
            self::Captured->value => [],
            self::Declined->value => [],
            self::Failed->value => [],
            self::OutcomeUnknown->value => [],
            self::Cancelled->value => [],
        ];
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next->value, self::transitionMatrix()[$this->value] ?? [], true);
    }

    public function isTerminal(): bool
    {
        return self::transitionMatrix()[$this->value] === [];
    }

    /**
     * Whether this state proves the provider definitely did NOT take
     * the money. Only these states may ever license a fresh attempt
     * for the same PaymentIntent.
     */
    public function provesNoMoneyMoved(): bool
    {
        return match ($this) {
            self::Declined, self::Failed, self::Cancelled => true,
            default => false,
        };
    }
}
