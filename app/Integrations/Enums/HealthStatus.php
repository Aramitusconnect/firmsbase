<?php

declare(strict_types=1);

namespace App\Integrations\Enums;

/**
 * HealthStatus — result vocabulary for SupportsHealthCheckContract::
 * checkHealth() and the future `integration_connection_health` table
 * (Checkpoint 8, checkpoint-00-final-specification.md §5). Defined now
 * purely as a vocabulary-level enum; no persistence exists yet.
 */
enum HealthStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case TokenExpired = 'token_expired';
    case RateLimited = 'rate_limited';
    case ProviderOutage = 'provider_outage';
    case Disconnected = 'disconnected';
    case Unknown = 'unknown';
}
