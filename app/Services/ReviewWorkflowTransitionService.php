<?php

namespace App\Services;

/**
 * ReviewWorkflowTransitionService — the ONE shared state machine for
 * both FormDraftStatus and GeneratedDocumentStatus, which have
 * identical values and an identical transition graph. Operates on
 * plain string values (works with either enum's ->value) so the graph
 * is defined exactly once rather than duplicated across
 * FormReviewService and DocumentReviewService.
 */
class ReviewWorkflowTransitionService
{
    private const ALLOWED_TRANSITIONS = [
        'draft' => ['needs_data', 'ready_for_review'],
        'needs_data' => ['ready_for_review'],
        'ready_for_review' => ['attorney_review'],
        'attorney_review' => ['approved', 'rejected', 'revised'],
        'revised' => ['ready_for_review'],
        'approved' => ['archived'],
        'rejected' => ['archived'],
        'archived' => [],
    ];

    public function isTransitionAllowed(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED_TRANSITIONS[$from] ?? [], true);
    }

    public function assertTransitionAllowed(string $from, string $to): void
    {
        if (! $this->isTransitionAllowed($from, $to)) {
            throw new \RuntimeException("Transition from '{$from}' to '{$to}' is not allowed.");
        }
    }
}
