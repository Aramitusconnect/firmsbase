<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MatterStatus;
use App\Models\FirmUser;
use App\Models\Matter;
use RuntimeException;

/**
 * MatterClosingService — Mission 5A (Firm Daily-Workflow Completion).
 * Firm Feature Manifest §2's confirmed gap: MatterStatus::Closed/
 * Archived exist on the enum but no service ever wrote them (mirrors
 * MatterOpeningService's own gap-closing role for MatterStatus::Open —
 * this is the sibling service for the OTHER end of a matter's
 * lifecycle, deliberately kept separate from MatterOpeningService,
 * which explicitly documents itself as "the ONLY place a matter
 * transitions to open").
 *
 * Transition rules (deliberately strict — "reject invalid transition
 * with a clear RuntimeException" style, mirroring
 * TrustLedgerService::freeze()/close()'s own lifecycle-guard
 * convention, not this codebase's TrustLedgerService itself):
 *
 *   close():   Open, Active, WaitingOnClient, ReadyForReview, or
 *              FiledSubmitted -> Closed. A matter still short of Open
 *              (Draft/ConflictCheckRequired/ConflictReview) has never
 *              actually been opened for business and should be
 *              abandoned via a different path, not "closed"; an
 *              already-Closed or Archived matter is rejected outright,
 *              never a silent no-op (same discipline as
 *              TrustLedgerService::close() rejecting an already-Closed
 *              ledger).
 *   archive():  ONLY Closed -> Archived. A matter must be formally
 *              closed first — archiving is a later, separate
 *              housekeeping step (e.g. after a retention period), not
 *              a shortcut around closing. Archiving an already-Archived
 *              matter, or archiving any not-yet-Closed matter, is
 *              rejected.
 *
 * Both methods are wrapped in TenantContextService::runWithFirmContext()
 * (matters is FORCE-RLS protected) and record a TimelineEvent via the
 * existing TimelineEventRecorder — the same "no other service should
 * call TimelineEvent::create() directly" discipline MatterOpeningService
 * itself follows, using plain string event types ('matter_closed'/
 * 'matter_archived') since no DomainEventType case exists for either
 * transition and this service does not own that enum.
 */
class MatterClosingService
{
    private const CLOSABLE_STATUSES = [
        MatterStatus::Open,
        MatterStatus::Active,
        MatterStatus::WaitingOnClient,
        MatterStatus::ReadyForReview,
        MatterStatus::FiledSubmitted,
    ];

    public function __construct(
        private readonly TimelineEventRecorder $timeline = new TimelineEventRecorder,
    ) {}

    /**
     * @throws RuntimeException if the matter is not in a status that can close
     */
    public function close(Matter $matter, ?FirmUser $actor = null): Matter
    {
        return (new TenantContextService)->runWithFirmContext($matter->firm_id, function () use ($matter, $actor) {
            $fresh = Matter::query()->where('id', $matter->id)->firstOrFail();

            if (! in_array($fresh->status, self::CLOSABLE_STATUSES, true)) {
                throw new RuntimeException(
                    "Matter cannot be closed from its current status ({$fresh->status->value}). Only Open, Active, Waiting on Client, Ready for Review, or Filed/Submitted matters may be closed."
                );
            }

            $fresh->update([
                'status' => MatterStatus::Closed,
                'closed_at' => now(),
            ]);

            $this->timeline->record($fresh->firm, 'matter_closed', $fresh, $actor?->user);

            return $fresh->fresh();
        });
    }

    /**
     * @throws RuntimeException if the matter is not Closed
     */
    public function archive(Matter $matter, ?FirmUser $actor = null): Matter
    {
        return (new TenantContextService)->runWithFirmContext($matter->firm_id, function () use ($matter, $actor) {
            $fresh = Matter::query()->where('id', $matter->id)->firstOrFail();

            if ($fresh->status !== MatterStatus::Closed) {
                throw new RuntimeException(
                    "Matter cannot be archived from its current status ({$fresh->status->value}). Only a Closed matter may be archived."
                );
            }

            $fresh->update([
                'status' => MatterStatus::Archived,
            ]);

            $this->timeline->record($fresh->firm, 'matter_archived', $fresh, $actor?->user);

            return $fresh->fresh();
        });
    }
}
