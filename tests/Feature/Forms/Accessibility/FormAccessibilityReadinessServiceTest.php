<?php

namespace Tests\Feature\Forms\Accessibility;

use App\Services\FormAccessibilityReadinessService;
use Tests\TestCase;

class FormAccessibilityReadinessServiceTest extends TestCase
{
    private FormAccessibilityReadinessService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FormAccessibilityReadinessService();
    }

    public function test_checklist_returns_the_five_required_checks(): void
    {
        $checklist = $this->service->checklist();

        $this->assertCount(5, $checklist);
        $this->assertArrayHasKey('keyboard_accessible_actions', $checklist);
        $this->assertArrayHasKey('visible_focus_states', $checklist);
        $this->assertArrayHasKey('accessible_labels', $checklist);
        $this->assertArrayHasKey('readable_validation_errors', $checklist);
        $this->assertArrayHasKey('accessible_checklist_controls', $checklist);
    }

    public function test_is_complete_requires_every_check_confirmed(): void
    {
        $this->assertFalse($this->service->isComplete([]));
        $this->assertFalse($this->service->isComplete(['keyboard_accessible_actions']));

        $this->assertTrue($this->service->isComplete(array_keys($this->service->checklist())));
    }

    public function test_extra_unknown_confirmed_keys_do_not_break_completeness(): void
    {
        $allPlusExtra = array_merge(array_keys($this->service->checklist()), ['something_else']);

        $this->assertTrue($this->service->isComplete($allPlusExtra));
    }
}
