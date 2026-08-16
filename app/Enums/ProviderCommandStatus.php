<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ProviderCommandStatus — FirmsVault Pay Gate A2. The MUTABLE execution
 * metadata half of a ProviderCommand (v1.4 §12); the immutable envelope
 * half never changes at all.
 *
 * A ProviderCommand is created INSIDE the financial domain transaction
 * (v1.4 §14) and therefore commits or rolls back with it. It is NOT the
 * at-most-once send gate — that remains
 * App\Integrations\Billing\ProviderOperationAttemptService, which runs
 * on an independent durable connection at the moment of the outbound
 * call. See docs/payments/gate-a2-compatibility-decision.md for why
 * these two must be different objects.
 */
enum ProviderCommandStatus: string
{
    /** Committed with the domain transaction; not yet handed to a worker. */
    case Pending = 'pending';

    /** A worker has taken it and is executing (or has executed) the send. */
    case Dispatched = 'dispatched';

    case Succeeded = 'succeeded';

    /** Definite negative outcome — the provider did not act on it. */
    case Failed = 'failed';

    /** Economic outcome genuinely undetermined. Never auto-retried. */
    case OutcomeUnknown = 'outcome_unknown';

    /**
     * @return array<string, list<string>>
     */
    public static function transitionMatrix(): array
    {
        return [
            self::Pending->value => [self::Dispatched->value, self::Failed->value],
            self::Dispatched->value => [
                self::Succeeded->value,
                self::Failed->value,
                self::OutcomeUnknown->value,
            ],
            self::Succeeded->value => [],
            self::Failed->value => [],
            self::OutcomeUnknown->value => [],
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
}
