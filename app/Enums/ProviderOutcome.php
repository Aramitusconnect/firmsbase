<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ProviderOutcome — FirmsVault Pay Gate A3 (v1.4 §8). The CANONICAL,
 * provider-neutral outcome vocabulary every PaymentProviderAdapter must
 * translate its native states into. Deliberately minimal: exactly the
 * five economic outcomes POC #1 requires, plus ONE adapter-protocol
 * signal (§19) that is never an economic outcome.
 *
 * Provider-specific adapters (the later Finix adapter, Gate B) map
 * their native states INTO these cases. PaymentCore never sees a
 * provider-native state.
 */
enum ProviderOutcome: string
{
    /** The provider definitively moved the money. */
    case Succeeded = 'succeeded';

    /** The provider positively refused; no money moved. */
    case Declined = 'declined';

    /** Definitive provider-side failure; no money moved. */
    case Failed = 'failed';

    /**
     * The economic outcome is genuinely undetermined. Never a failure,
     * never a license to retry — resolution comes only from an
     * authoritative outcome lookup or provider event against the
     * ORIGINAL command (v1.4 §14/§17).
     */
    case OutcomeUnknown = 'outcome_unknown';

    case Cancelled = 'cancelled';

    /**
     * ADAPTER-PROTOCOL SIGNAL, not an economic outcome (v1.4 §19): the
     * provider recognized this command's idempotency identity as one it
     * has already received. The executor must NOT treat this as a
     * failure and must NOT issue another financial command — it must
     * perform an outcome lookup and reconcile the ORIGINAL transaction.
     * This value may never be persisted onto a PaymentAttempt or
     * PaymentRefund.
     */
    case DuplicateRequiresLookup = 'duplicate_requires_lookup';

    /** True only for outcomes that may be persisted as economic results. */
    public function isEconomicOutcome(): bool
    {
        return $this !== self::DuplicateRequiresLookup;
    }
}
