<?php

namespace Tests\Feature\Forms\Review;

use App\Services\ReviewWorkflowTransitionService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Asserts the exact shared transition graph used by BOTH FormDraftStatus
 * and GeneratedDocumentStatus (identical values, one shared service).
 */
class ReviewWorkflowTransitionServiceTest extends TestCase
{
    private ReviewWorkflowTransitionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReviewWorkflowTransitionService();
    }

    #[DataProvider('allowedTransitions')]
    public function test_allowed_transitions_are_permitted(string $from, string $to): void
    {
        $this->assertTrue($this->service->isTransitionAllowed($from, $to));
        $this->service->assertTransitionAllowed($from, $to);
        $this->addToAssertionCount(1);
    }

    public static function allowedTransitions(): array
    {
        return [
            'draft -> needs_data' => ['draft', 'needs_data'],
            'draft -> ready_for_review' => ['draft', 'ready_for_review'],
            'needs_data -> ready_for_review' => ['needs_data', 'ready_for_review'],
            'ready_for_review -> attorney_review' => ['ready_for_review', 'attorney_review'],
            'attorney_review -> approved' => ['attorney_review', 'approved'],
            'attorney_review -> rejected' => ['attorney_review', 'rejected'],
            'attorney_review -> revised' => ['attorney_review', 'revised'],
            'revised -> ready_for_review' => ['revised', 'ready_for_review'],
            'approved -> archived' => ['approved', 'archived'],
            'rejected -> archived' => ['rejected', 'archived'],
        ];
    }

    #[DataProvider('disallowedTransitions')]
    public function test_disallowed_transitions_are_rejected(string $from, string $to): void
    {
        $this->assertFalse($this->service->isTransitionAllowed($from, $to));

        $this->expectException(\RuntimeException::class);
        $this->service->assertTransitionAllowed($from, $to);
    }

    public static function disallowedTransitions(): array
    {
        return [
            'draft -> approved (skips workflow)' => ['draft', 'approved'],
            'draft -> attorney_review (skips ready_for_review)' => ['draft', 'attorney_review'],
            'rejected -> ready_for_review (rejected only permits archived)' => ['rejected', 'ready_for_review'],
            'archived -> anything' => ['archived', 'draft'],
            'needs_data -> approved' => ['needs_data', 'approved'],
            'approved -> rejected' => ['approved', 'rejected'],
        ];
    }

    public function test_archived_permits_no_further_transitions(): void
    {
        foreach (['draft', 'needs_data', 'ready_for_review', 'attorney_review', 'approved', 'rejected', 'revised', 'archived'] as $to) {
            $this->assertFalse($this->service->isTransitionAllowed('archived', $to));
        }
    }
}
