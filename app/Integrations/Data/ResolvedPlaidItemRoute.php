<?php

declare(strict_types=1);

namespace App\Integrations\Data;

/**
 * ResolvedPlaidItemRoute — the ONLY thing
 * App\Integrations\Support\PlaidItemRoutingService::resolveByItemId()
 * returns: a bounded connection IDENTITY for a Plaid Item route, never
 * a secret, never connection metadata, never a hydrated model — mirrors
 * App\Integrations\Data\ResolvedGmailMailboxRoute's identical shape and
 * discipline (checkpoint3-combined-design.md §6.4.3), applied to the
 * new, dedicated `integration_plaid_item_routes` table instead of
 * `integration_gmail_mailbox_routes`. Deliberately a plain, final,
 * immutable data object — not an Eloquent model — so it can never
 * accidentally be passed to a place that expects RLS-scoped data or
 * serialized wholesale into a log line.
 *
 * No `providerKey` field (unlike ResolvedWebhookConnection): every row
 * in `integration_plaid_item_routes` is Plaid-only by construction, so
 * there is nothing to disambiguate here.
 *
 * FirmsVault Live Integrations, Checkpoint 4
 * (checkpoint4-design-plaid-provider-core.md §11.2, binding per
 * checkpoint4-combined-design.md §1.1.1).
 */
final class ResolvedPlaidItemRoute
{
    public function __construct(
        public readonly int $firmId,
        public readonly int $firmIntegrationId,
        public readonly int $integrationProviderId,
    ) {}
}
