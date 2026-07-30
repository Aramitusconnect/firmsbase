<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use RuntimeException;

/**
 * GmailMailboxAlreadyRoutedException — thrown by
 * `App\Integrations\Support\GmailMailboxRoutingService::route()` when the
 * mailbox being claimed is already routed to a DIFFERENT connection
 * (Checkpoint 8.2 §A7b).
 *
 * A mailbox's lookup hash is GLOBALLY unique, not unique per firm,
 * because Gmail's shared Pub/Sub topic delivers an inbound notification
 * carrying only the mailbox address: if two firms could both claim one
 * mailbox, there would be no way to decide which firm's data an inbound
 * message belongs to. Failing closed here is the entire point.
 *
 * This class deliberately names no table. The routing table may be
 * referenced by exactly one file — enforced by
 * IntegrationGmailMailboxRoutesNoRlsAndHmacOnlyColumnTest — and widening
 * that allowlist for a docblock's convenience would trade a real
 * containment guarantee for prose.
 *
 * WHY IT IS A TYPED EXCEPTION RATHER THAN A RAW UNIQUE-VIOLATION. The
 * claim now happens BEFORE the provider `watch()` call, so this is the
 * signal that no network call was made and none should be: a definite,
 * local, non-ambiguous failure. `ProviderConnectionService` classifies it
 * as `bootstrap_failed` rather than `reconciliation_required` precisely
 * because nothing could have happened at the provider — there is nothing
 * to reconcile.
 */
final class GmailMailboxAlreadyRoutedException extends RuntimeException
{
    public function __construct(
        public readonly int $requestedFirmIntegrationId,
        public readonly int $owningFirmIntegrationId,
    ) {
        parent::__construct(
            'The requested Gmail mailbox is already routed to connection '
                .$owningFirmIntegrationId.' and cannot be claimed by connection '
                .$requestedFirmIntegrationId.'. Gmail mailbox routes are globally unique.'
        );
    }
}
