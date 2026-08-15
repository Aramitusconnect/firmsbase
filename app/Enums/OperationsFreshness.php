<?php

namespace App\Enums;

/**
 * OperationsFreshness — how much an operational observation can still
 * be trusted as describing NOW. Operations Control Plane addition.
 *
 * A health check that passed once and has not run since is not
 * healthy; it is stale. Without this distinction a console shows a
 * green light indefinitely after monitoring silently stops, which is
 * strictly worse than showing nothing at all. Applies to any
 * timestamped operational signal (health observations, deployment
 * heartbeats, scheduler heartbeats), not just health checks.
 */
enum OperationsFreshness: string
{
    /** Observed within its expected cadence plus grace. */
    case Fresh = 'fresh';

    /** Observed, but too long ago to describe the present. */
    case Stale = 'stale';

    /** No observation has ever been recorded. */
    case NeverObserved = 'never_observed';

    /**
     * No expected cadence is defined for this signal, so "overdue"
     * is not a question this platform can answer about it.
     */
    case CadenceUnknown = 'cadence_unknown';

    public function label(): string
    {
        return match ($this) {
            self::Fresh => 'Fresh',
            self::Stale => 'Stale',
            self::NeverObserved => 'Never Observed',
            self::CadenceUnknown => 'Cadence Unknown',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Fresh => 'success',
            self::Stale => 'warning',
            self::NeverObserved => 'gray',
            self::CadenceUnknown => 'gray',
        };
    }

    /**
     * True when the underlying observation is too old, or too absent,
     * to be presented as describing the current state.
     */
    public function isTrustworthyAsCurrent(): bool
    {
        return $this === self::Fresh;
    }
}
