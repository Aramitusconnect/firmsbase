<?php

declare(strict_types=1);

namespace App\Integrations\Enums;

/**
 * ProviderWebhookSubscriptionStatus — lifecycle state of an
 * `integration_provider_webhook_subscriptions` row (FirmsVault Live
 * Integrations, Checkpoint 2, checkpoint2-design-sync-webhooks.md §3.2;
 * checkpoint2-combined-design.md §2 P-17). Plain string column, no
 * DB-level enum type — mirrors CursorStatus's identical shape on
 * integration_sync_cursors.status.
 *
 * `Active` — a currently live remote subscription FirmsVault believes
 * is receiving notifications; the ONLY status the partial unique index
 * `integration_provider_webhook_subscriptions_one_active_per_resource`
 * enforces uniqueness against.
 * `Expired` — reserved for a future explicit-expiry sweep; nothing in
 * this checkpoint writes it (RenewGraphSubscriptionJob always either
 * renews successfully back to Active or transitions to RenewalFailed —
 * it never leaves a row sitting in Expired on its own).
 * `RenewalFailed` — set exclusively by RenewGraphSubscriptionJob::failed()
 * once retries are exhausted; visible on the existing per-connection
 * health surface via the accompanying HealthStateService::recordProviderError()
 * call, no new UI needed.
 * `Removed` — reserved for a future explicit unsubscribe/disconnect
 * teardown path; nothing in this checkpoint writes it.
 */
enum ProviderWebhookSubscriptionStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case RenewalFailed = 'renewal_failed';
    case Removed = 'removed';
}
