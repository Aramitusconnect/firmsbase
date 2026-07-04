<?php

namespace App\Enums;

/**
 * HealthCheckStatus — health_checks.status. No exact value list given
 * by the PDF — recommendation. Unknown covers a check that could not
 * run at all (e.g. its dependency was unreachable), distinct from
 * Unhealthy (the check ran and found a real problem).
 */
enum HealthCheckStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unhealthy = 'unhealthy';
    case Unknown = 'unknown';
}
