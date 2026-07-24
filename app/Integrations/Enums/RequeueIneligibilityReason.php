<?php

declare(strict_types=1);

namespace App\Integrations\Enums;

/**
 * RequeueIneligibilityReason — Checkpoint 10 (frozen-design-post-
 * security-review.md §5; agent-10h-architecture-security-review.md §4).
 * Closed vocabulary of the reasons
 * IntegrationOutboxEventService::requeue()/
 * SyncItemService::requeueFromFailedPermanent() can reject a requeue
 * attempt — both guarded UPDATEs collapse every rejection cause into
 * one indistinguishable `null` return, so this enum exists purely to
 * let a read-only diagnostic re-check (diagnoseRequeueIneligibility() on
 * both services) surface a specific, UI-facing reason.
 *
 * Explicitly non-authoritative: never gates or retries the actual
 * requeue — see diagnoseRequeueIneligibility()'s own docblock on both
 * services for the full discipline.
 */
enum RequeueIneligibilityReason: string
{
    case NotFoundOrCrossFirm = 'not_found_or_cross_firm';
    case NotEligibleStatus = 'not_eligible_status';
    case RequeueCeilingReached = 'requeue_ceiling_reached'; // outbox only; never returned for sync items
    case Superseded = 'superseded';
    case ConnectionDisconnected = 'connection_disconnected';
    case CredentialRevoked = 'credential_revoked';

    /**
     * Short, human-readable, non-secret message safe to render directly
     * in a Filament notification body.
     */
    public function description(): string
    {
        return match ($this) {
            self::NotFoundOrCrossFirm => 'This item could not be found for your firm.',
            self::NotEligibleStatus => 'This item is no longer in a state that can be requeued.',
            self::RequeueCeilingReached => 'This item has reached its requeue limit.',
            self::Superseded => 'This item was superseded by a later, already-processed item and can no longer be requeued.',
            self::ConnectionDisconnected => 'This connection has been disconnected — reconnect the integration before requeuing.',
            self::CredentialRevoked => 'This connection has no active credential — reconnect the integration before requeuing.',
        };
    }
}
