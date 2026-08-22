<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

/**
 * SupportsPushSyncContract — implemented by providers that support
 * FirmsBase pushing (writing) resources to them.
 */
interface SupportsPushSyncContract
{
    /**
     * @return string[] resource type identifiers this provider can
     *                  accept a push for (see
     *                  App\Integrations\Enums\ResourceType for the
     *                  current documented vocabulary).
     */
    public function pushableResourceTypes(): array;

    /**
     * Push a single resource to the provider.
     *
     * @param  array<string, mixed>  $context  caller-supplied connection/
     *                                         auth context.
     * @param  string  $resourceType  one of pushableResourceTypes().
     * @param  array<string, mixed>  $payload  the outbound payload.
     * @return array<string, mixed> shape must include enough
     *                              information for the caller to
     *                              record a firstOrCreate-shaped
     *                              external mapping (e.g. an external
     *                              id) — never a bare create().
     */
    public function push(array $context, string $resourceType, array $payload): array;
}
