<?php

namespace App\Enums;

/**
 * HealthCheckMonitoringType — what KIND of evidence a registered
 * health check actually produces. Operations Control Plane addition.
 *
 * This exists because "what status did the check report" and "is
 * there anything real behind the check at all" are two genuinely
 * different questions, and conflating them is exactly how a console
 * ends up showing a green light for a surface nobody is monitoring.
 * HealthCheckStatus answers the first; this enum answers the second.
 *
 * Declared per registration on HealthCheckRegistry (never inferred
 * from a class name), stamped onto every HealthCheckResult the
 * registry runs, and persisted into the pre-existing, previously
 * unused `health_checks.metadata_json` column — no schema change.
 */
enum HealthCheckMonitoringType: string
{
    /**
     * A real probe against a real dependency outside this process
     * (an external provider, an outbound request, a live endpoint).
     * Nothing in this codebase qualifies today.
     */
    case LiveProbe = 'live_probe';

    /**
     * Real, first-hand evidence read from this platform's own
     * runtime state — the queue tables, the scheduler heartbeat, a
     * tenant-isolation scan. Not an external probe, but genuinely
     * measured rather than declared.
     */
    case InternalMetric = 'internal_metric';

    /**
     * A check that only reads configuration/declared intent. Real,
     * but it proves what the platform is CONFIGURED to do, never
     * what it is actually doing.
     */
    case ConfigurationCheck = 'configuration_check';

    /**
     * No probe of any kind exists behind this check — a stub, or a
     * check type that is not registered at all. A check of this kind
     * must never report Healthy.
     */
    case NotMonitored = 'not_monitored';

    public function label(): string
    {
        return match ($this) {
            self::LiveProbe => 'Live Probe',
            self::InternalMetric => 'Internal Metric',
            self::ConfigurationCheck => 'Configuration Check',
            self::NotMonitored => 'Not Monitored',
        };
    }

    /**
     * True when a status reported under this monitoring type is
     * backed by something real enough to act on. Deliberately used
     * to decide what may count toward an aggregate "healthy" claim.
     */
    public function isRealEvidence(): bool
    {
        return $this !== self::NotMonitored;
    }
}
