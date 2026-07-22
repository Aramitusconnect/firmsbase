<?php

declare(strict_types=1);

namespace App\Integrations\Enums;

/**
 * WebhookVerificationOutcome — lifecycle/outcome state of an
 * `integration_webhook_receipts` row (Checkpoint 7,
 * reviews/checkpoint-07/frozen-design-post-security-review.md §10.1).
 * Plain string column, no DB-level enum type, matching every other
 * status-shaped column in this mission.
 *
 * Checkpoint 7's actual write path
 * (App\Integrations\Services\InboundWebhookReceiptService) only ever
 * inserts a receipt row AFTER routing resolution AND signature
 * verification have already succeeded (frozen design §8.1's
 * acknowledgment matrix rows 1-2, 10, 12 — every pre-verification
 * failure, rows 3-5 and 7-9, writes NO receipt row at all). This
 * checkpoint therefore only ever persists `Verified` or `Malformed` as
 * a row's actual `verification_outcome` value. The remaining cases
 * (`Pending`, `SignatureInvalid`, `RoutingUnresolved`, `Replayed`,
 * `Expired`, `Error`) exist for SCHEMA COMPLETENESS — matching the
 * column's own CHECK constraints and the full state space a future,
 * separately-reviewed checkpoint might need (e.g. a broader
 * pre-verification audit trail) — not because any code path in this
 * checkpoint writes them.
 */
enum WebhookVerificationOutcome: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case SignatureInvalid = 'signature_invalid';
    case RoutingUnresolved = 'routing_unresolved';
    case Malformed = 'malformed';
    case Replayed = 'replayed';
    case Expired = 'expired';
    case Error = 'error';
}
