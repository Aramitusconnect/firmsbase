<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

/**
 * SupportsPullSyncContract — implemented by providers that support
 * FirmsBase pulling (reading) resources from them. Deliberately
 * separate from SupportsIncrementalSyncContract so a full-list-only
 * provider is never forced to fake a delta/cursor token
 * (provider-contracts.md).
 */
interface SupportsPullSyncContract
{
    /**
     * @return string[] resource type identifiers this provider can
     *                  pull (see App\Integrations\Enums\ResourceType
     *                  for the current documented vocabulary).
     */
    public function pullableResourceTypes(): array;

    /**
     * Pull one page of the given resource type.
     *
     * @param  array<string, mixed>  $context  caller-supplied connection/
     *                                         auth context.
     * @param  string  $resourceType  one of pullableResourceTypes().
     * @param  string|null  $cursor  opaque pagination cursor from a
     *                               previous call, or null for the first
     *                               page.
     * @return array<string, mixed> shape must include the pulled
     *                              items and the next cursor (or null
     *                              if exhausted).
     *
     *                               Checkpoint 2 (FirmsVault Live
     *                               Integrations, Microsoft 365 provider
     *                               — checkpoint2-design-sync-webhooks.md
     *                               §1.3; checkpoint2-combined-design.md
     *                               §2 P-16) addition: the returned array
     *                               may optionally include a
     *                               `'has_more' => bool` key. When
     *                               present, it authoritatively signals
     *                               whether another page should be
     *                               fetched in THIS run, independent of
     *                               whether `next_cursor` is null — a
     *                               provider whose continuation token
     *                               never goes null (e.g. a delta
     *                               query's terminal deltaLink) MUST
     *                               supply this key. When absent, the
     *                               caller falls back to the
     *                               pre-existing `next_cursor !== null`
     *                               rule, preserving every current
     *                               provider's exact behavior.
     */
    public function pull(array $context, string $resourceType, ?string $cursor): array;
}
