<?php

namespace App\Services;

use App\Enums\DomainEventType;
use App\Enums\MatterStatus;
use App\Models\ConflictCheckRun;
use App\Models\Matter;
use App\Models\User;
use App\Services\Automation\DomainEventRecorderService;

/**
 * MatterOpeningService — the ONLY place a matter may transition to
 * `open`. A matter must run a conflict check first and that check must
 * be clear (no confirmed conflicts, no unresolved possible matches) —
 * project rule: "Conflict checks must run before opening a matter."
 * Mirrors the ActivationChecklistService gate pattern from Phase 1.
 *
 * Event-Driven Automation Engine — openMatter() is the nearest real,
 * existing Matter transition with an actual call site (the audit found
 * Matter.stage has no mutation path in this codebase at all — set once
 * at creation, never changed after), so DomainEventType::MatterOpened
 * substitutes for the literal "MatterStageChanged" candidate starter.
 */
class MatterOpeningService
{
    public function __construct(
        private ConflictCheckService $conflictCheckService,
        private TimelineEventRecorder $timeline,
        private DomainEventRecorderService $domainEvents,
    ) {}

    /**
     * @param  array<int, string>  $searchTerms
     * @param  array<int, string>  $freeTextNames
     *
     * @throws \RuntimeException if the matter is not in a state that can request a check
     */
    public function requestConflictCheck(
        Matter $matter,
        array $searchTerms,
        array $freeTextNames = [],
        ?User $actor = null,
    ): ConflictCheckRun {
        if (! in_array($matter->status, [MatterStatus::Draft, MatterStatus::ConflictCheckRequired], true)) {
            throw new \RuntimeException(
                "Matter must be in draft or conflict_check_required status to request a conflict check, currently: {$matter->status->value}"
            );
        }

        if ($matter->status === MatterStatus::Draft) {
            (new TenantContextService)->runWithFirmContext($matter->firm_id, fn () => $matter->update(['status' => MatterStatus::ConflictCheckRequired]));
        }

        $this->conflictCheckService->run($matter, $searchTerms, $freeTextNames, $actor);

        return (new TenantContextService)->runWithFirmContext($matter->firm_id, function () use ($matter) {
            $matter->update(['status' => MatterStatus::ConflictReview]);

            // conflict_check_runs has permanent FORCE ROW LEVEL SECURITY
            // (Section 39A-3I) — this read needs the same active
            // context as the status update just above.
            return $matter->conflictCheckRuns()->latest('id')->firstOrFail();
        });
    }

    /**
     * @throws \RuntimeException if the matter is not in conflict_review,
     *                           the run does not belong to this matter, or the check is not clear
     */
    public function openMatter(Matter $matter, ConflictCheckRun $conflictCheckRun, ?User $actor = null): Matter
    {
        if ($matter->status !== MatterStatus::ConflictReview) {
            throw new \RuntimeException('Matter must be in conflict_review status to open.');
        }

        if ($conflictCheckRun->matter_id !== $matter->id) {
            throw new \RuntimeException('Conflict check run does not belong to this matter.');
        }

        // conflict_check_runs has permanent FORCE ROW LEVEL SECURITY
        // (Section 39A-3I) — fresh() re-reads the run row itself, so it
        // needs its own narrow context wrap here, independent of the
        // later status-update wrap below.
        $summary = (new TenantContextService)->runWithFirmContext(
            $matter->firm_id,
            fn () => $this->conflictCheckService->summarize($conflictCheckRun->fresh('results')),
        );

        if (! $summary->isClearToProceed()) {
            throw new \RuntimeException(
                'Matter cannot open: conflict check is not clear (unresolved possible matches or confirmed conflicts exist).'
            );
        }

        return (new TenantContextService)->runWithFirmContext($matter->firm_id, function () use ($matter, $actor) {
            $matter->update([
                'status' => MatterStatus::Open,
                'opened_at' => now(),
            ]);

            $this->timeline->record($matter->firm, 'matter_opened', $matter, $actor);

            $this->domainEvents->record($matter->firm, DomainEventType::MatterOpened, [
                'matter' => [
                    'id' => $matter->id,
                    'client_id' => $matter->client_id,
                    'assigned_attorney_id' => $matter->assigned_attorney_id,
                    'status' => $matter->status->value,
                ],
            ], subject: $matter);

            return $matter->fresh();
        });
    }
}
