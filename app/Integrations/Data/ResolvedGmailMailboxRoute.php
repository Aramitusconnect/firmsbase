<?php

declare(strict_types=1);

namespace App\Integrations\Data;

/**
 * ResolvedGmailMailboxRoute — the ONLY thing
 * App\Integrations\Support\GmailMailboxRoutingService::resolveByMailbox()
 * returns: a bounded connection IDENTITY for a Gmail mailbox route,
 * never a secret, never connection metadata, never a hydrated model —
 * mirrors App\Integrations\Data\ResolvedWebhookConnection's identical
 * shape and discipline (checkpoint3-combined-design.md §6.4.3),
 * applied to the new, dedicated `integration_gmail_mailbox_routes`
 * table instead of `integration_webhook_routing_index`. Deliberately a
 * plain, final, immutable data object — not an Eloquent model — so it
 * can never accidentally be passed to a place that expects RLS-scoped
 * data or serialized wholesale into a log line.
 *
 * No `providerKey` field (unlike ResolvedWebhookConnection): every row
 * in `integration_gmail_mailbox_routes` is Gmail-only by construction
 * (checkpoint3-design-sync-webhooks.md §6.4.2 — "provider = Google
 * Workspace (implicit)"), so there is nothing to disambiguate here.
 */
final class ResolvedGmailMailboxRoute
{
    public function __construct(
        public readonly int $firmId,
        public readonly int $firmIntegrationId,
        public readonly int $integrationProviderId,
    ) {}
}
