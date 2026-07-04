<?php

namespace App\Services;

use App\Enums\MatterStatus;
use App\Models\ConflictCheckRun;
use App\Models\Matter;
use App\Models\User;

/**
 * MatterOpeningService — the ONLY place a matter may transition to
 * `open`. A matter must run a conflict check first and that check must
 * be clear (no confirmed conflicts, no unresolved possible matches) —
 * project rule: "Conflict checks must run before opening a matter."
 * Mirrors the ActivationChecklistService gate pattern from Phase 1.
 */
class MatterOpeningService
{
    public function __construct(
        private ConflictCheckService $conflictCheckService,
        private TimelineEventRecorder $timeline,
    ) {
    }

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
            $matter->update(['status' => MatterStatus::ConflictCheckRequired]);
        }

        $this->conflictCheckService->run($matter, $searchTerms, $freeTextNames, $actor);

        $matter->update(['status' => MatterStatus::ConflictReview]);

        return $matter->conflictCheckRuns()->latest('id')->firstOrFail();
    }

    /**
     * @throws \RuntimeException if the matter is not in conflict_review,
     *   the run does not belong to this matter, or the check is not clear
     */
    public function openMatter(Matter $matter, ConflictCheckRun $conflictCheckRun, ?User $actor = null): Matter
    {
        if ($matter->status !== MatterStatus::ConflictReview) {
            throw new \RuntimeException('Matter must be in conflict_review status to open.');
        }

        if ($conflictCheckRun->matter_id !== $matter->id) {
            throw new \RuntimeException('Conflict check run does not belong to this matter.');
        }

        $summary = $this->conflictCheckService->summarize($conflictCheckRun->fresh('results'));

        if (! $summary->isClearToProceed()) {
            throw new \RuntimeException(
                'Matter cannot open: conflict check is not clear (unresolved possible matches or confirmed conflicts exist).'
            );
        }

        $matter->update([
            'status' => MatterStatus::Open,
            'opened_at' => now(),
        ]);

        $this->timeline->record($matter->firm, 'matter_opened', $matter, $actor);

        return $matter->fresh();
    }
}
