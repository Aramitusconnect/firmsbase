<?php

namespace App\Enums;

/**
 * HealthCheckStatus — health_checks.status. No exact value list given
 * by the PDF — recommendation. Unknown covers a check that could not
 * run at all (e.g. its dependency was unreachable), distinct from
 * Unhealthy (the check ran and found a real problem).
 *
 * Operations Control Plane addition: NotMonitored. Distinct from all
 * four states above, because none of them fit a check with no probe
 * behind it — Healthy is an outright lie, Unknown implies a probe
 * that tried and could not reach its dependency, and Degraded/
 * Unhealthy imply a real problem was observed. `health_checks.status`
 * is a plain `string` column with no database enum and no CHECK
 * constraint (see that table's own migration), so adding this case is
 * an application-level change only — no migration, no schema change.
 */
enum HealthCheckStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unhealthy = 'unhealthy';
    case Unknown = 'unknown';
    case NotMonitored = 'not_monitored';

    public function label(): string
    {
        return match ($this) {
            self::Healthy => 'Healthy',
            self::Degraded => 'Degraded',
            self::Unhealthy => 'Critical',
            self::Unknown => 'Unknown',
            self::NotMonitored => 'Not Monitored',
        };
    }

    /**
     * The Filament badge colour for this status. NotMonitored is
     * deliberately grey, never green — an unmonitored surface is not
     * a passing surface.
     */
    public function color(): string
    {
        return match ($this) {
            self::Healthy => 'success',
            self::Degraded => 'warning',
            self::Unhealthy => 'danger',
            self::Unknown => 'warning',
            self::NotMonitored => 'gray',
        };
    }

    /**
     * True only for a status that represents a genuinely observed
     * passing signal. NotMonitored/Unknown are excluded on purpose:
     * "nobody looked" is not "nothing is wrong."
     */
    public function isObservedPassing(): bool
    {
        return $this === self::Healthy;
    }

    /**
     * True when this status represents a real, observed problem
     * requiring operator attention (as opposed to an absence of
     * evidence, which is handled separately).
     */
    public function isObservedProblem(): bool
    {
        return $this === self::Degraded || $this === self::Unhealthy;
    }
}
