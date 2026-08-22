<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

/**
 * SupportsHealthCheckContract — implemented by providers that expose a
 * way to check connection health independent of an actual sync/push
 * attempt. The `integration_connection_health` table (Checkpoint 8)
 * that will persist results of checkHealth() does not exist yet
 * (checkpoint-00-final-specification.md §5, §21) — interface shape
 * only.
 */
interface SupportsHealthCheckContract
{
    /**
     * A documentation-only description of how this provider's health
     * check works (e.g. "GET the identity endpoint and confirm a 200").
     * Never executable — must never be passed to an HTTP client or any
     * other code path that would treat it as a real URL/command.
     */
    public function healthCheckEndpointConvention(): string;

    /**
     * Perform a health check for the given connection context.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed> health result shape (e.g. status,
     *                              checked-at timestamp, detail).
     */
    public function checkHealth(array $context): array;
}
