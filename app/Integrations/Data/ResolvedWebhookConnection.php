<?php

declare(strict_types=1);

namespace App\Integrations\Data;

/**
 * ResolvedWebhookConnection — the ONLY thing
 * App\Integrations\Services\WebhookConnectionResolverService::resolveConnectionIdentity()
 * (Step 1 of the frozen design's four-step identity-scoped
 * secret-resolution mechanism,
 * reviews/checkpoint-07/frozen-design-post-security-review.md §5)
 * returns: a bounded connection IDENTITY, never a secret, never
 * connection metadata, never a hydrated model. Deliberately a plain,
 * final, immutable data object — not an Eloquent model — so it can
 * never accidentally be passed to a place that expects RLS-scoped
 * data or serialized wholesale into a log line.
 */
final class ResolvedWebhookConnection
{
    public function __construct(
        public readonly int $firmId,
        public readonly int $firmIntegrationId,
        public readonly int $integrationProviderId,
        public readonly string $providerKey,
    ) {
    }
}
