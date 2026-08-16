<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * PaymentIntentStatus — FirmsVault Pay Gate A2. The deliberately tiny
 * lifecycle of a PaymentIntent, which is an INSTRUCTION, not an
 * execution record (execution lives on PaymentAttempt).
 *
 * Draft      — still being composed; material fields may change freely.
 * Frozen     — material fields are immutable from here on
 *              (FirmsVault Pay Architecture v3.1 §17). Only a frozen
 *              intent may ever be executed.
 * Superseded — a NEW intent replaced this one. History is never
 *              rewritten: the superseded row keeps its original
 *              material values forever and points at its successor.
 * Cancelled  — abandoned without execution.
 *
 * There is deliberately no "Executed"/"Paid" case: how much of an
 * intent actually succeeded is derived from its PaymentAttempts, and
 * duplicating that onto the intent would create a second, driftable
 * source of truth for the same fact.
 */
enum PaymentIntentStatus: string
{
    case Draft = 'draft';
    case Frozen = 'frozen';
    case Superseded = 'superseded';
    case Cancelled = 'cancelled';

    /**
     * Whether material fields may still be mutated in place.
     */
    public function allowsMaterialMutation(): bool
    {
        return $this === self::Draft;
    }

    public function isExecutable(): bool
    {
        return $this === self::Frozen;
    }
}
