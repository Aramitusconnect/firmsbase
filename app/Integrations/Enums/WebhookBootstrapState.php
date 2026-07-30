<?php

declare(strict_types=1);

namespace App\Integrations\Enums;

/**
 * WebhookBootstrapState — Checkpoint 8.2 §A7b. The lifecycle of ONE
 * connection's webhook-subscription bootstrap, tracked separately from
 * `ConnectionStatus`.
 *
 * WHY A SECOND, NARROW STATE INSTEAD OF A NEW ConnectionStatus CASE.
 * Whether the provider-side webhook subscriptions exist is a genuinely
 * different question from whether the connection is usable. A connection
 * whose OAuth completed but whose subscriptions failed to bootstrap IS
 * connected and IS syncable on a schedule or on demand — it simply will
 * not receive push notifications yet. Folding that into
 * `ConnectionStatus` would either mark a working connection non-Active
 * (silently disabling every `status === Active` guard in the codebase,
 * including `PullSyncJob`'s) or hide the degradation entirely. Neither is
 * honest. This enum makes the degradation visible without lying about the
 * connection.
 *
 * WHAT IT REPLACED. The bootstrap used to run INSIDE the OAuth
 * completion transaction, and that method's own docblock said the quiet
 * part out loud: a failure "rolls back the entire OAuth connect, leaving
 * the connection never `Active`, rather than silently degrading to
 * manual-sync-only." So one transient provider hiccup during
 * `subscribe()` discarded a completed, valid authorization — including
 * the credential just exchanged — and the user had to start over. Worse,
 * that provider call happened while the OAuth transaction held
 * `FOR UPDATE` on the connection row, the exact shape Checkpoint 8.1
 * proved deadlocks durable cross-session writes.
 */
enum WebhookBootstrapState: string
{
    /**
     * This connection's provider does not support webhooks, or requested
     * no webhook-capable resource types. Nothing to bootstrap, ever.
     */
    case NotRequired = 'not_required';

    /**
     * OAuth completed and committed; the subscriptions have not been
     * created yet. Set inside the OAuth transaction, so it is durable the
     * moment the connection becomes Active.
     */
    case Pending = 'pending_webhook_bootstrap';

    /** Every requested webhook subscription exists at the provider. */
    case Complete = 'bootstrap_complete';

    /**
     * The bootstrap failed for a reason a retry can plausibly fix
     * (timeout, rate limit, transient 5xx). A retry is queued; the
     * connection stays usable in the meantime.
     */
    case PendingRetry = 'bootstrap_pending_retry';

    /**
     * The bootstrap failed definitively (bad scope, provider rejected the
     * resource, misconfiguration). No automated retry will help — the
     * firm or an operator must act.
     */
    case Failed = 'bootstrap_failed';

    /**
     * A subscribe call's outcome is unknown, so a subscription may or may
     * not exist at the provider. Never retried automatically: a blind
     * retry is exactly how a connection ends up with duplicate
     * subscriptions and duplicated inbound webhook traffic.
     */
    case ReconciliationRequired = 'bootstrap_reconciliation_required';

    /** True when push delivery is not (yet) working for this connection. */
    public function isDegraded(): bool
    {
        return in_array($this, [self::Pending, self::PendingRetry, self::Failed, self::ReconciliationRequired], true);
    }

    /** True when an automated retry is appropriate and expected. */
    public function isRetryable(): bool
    {
        return in_array($this, [self::Pending, self::PendingRetry], true);
    }

    /** True when only a human can move this forward. */
    public function needsHumanAttention(): bool
    {
        return in_array($this, [self::Failed, self::ReconciliationRequired], true);
    }

    /**
     * A short, non-technical explanation for the firm-facing UI. Says what
     * is and is not working — never "everything is fine" when it is not.
     */
    public function firmFacingSummary(): string
    {
        return match ($this) {
            self::NotRequired => 'This provider does not use webhook subscriptions.',
            self::Complete => 'Real-time updates are active.',
            self::Pending => 'Real-time updates are still being set up. Scheduled and manual syncs work normally in the meantime.',
            self::PendingRetry => 'Real-time updates could not be set up yet and will be retried automatically. Scheduled and manual syncs work normally in the meantime.',
            self::Failed => 'Real-time updates could not be set up. Scheduled and manual syncs still work, but changes will not arrive automatically until this is resolved.',
            self::ReconciliationRequired => 'Real-time updates need to be checked manually before they can be relied on. Scheduled and manual syncs work normally in the meantime.',
        };
    }
}
