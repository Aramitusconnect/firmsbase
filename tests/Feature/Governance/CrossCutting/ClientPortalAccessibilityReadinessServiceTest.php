<?php

namespace Tests\Feature\Governance\CrossCutting;

use App\Services\ClientPortalAccessibilityReadinessService;
use Tests\TestCase;

class ClientPortalAccessibilityReadinessServiceTest extends TestCase
{
    private ClientPortalAccessibilityReadinessService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ClientPortalAccessibilityReadinessService();
    }

    public function test_checklist_contains_all_seven_required_checks(): void
    {
        $checklist = $this->service->checklist();

        $this->assertCount(7, $checklist);
        $this->assertArrayHasKey('keyboard_navigation', $checklist);
        $this->assertArrayHasKey('visible_focus_states', $checklist);
        $this->assertArrayHasKey('readable_contrast', $checklist);
        $this->assertArrayHasKey('labels', $checklist);
        $this->assertArrayHasKey('accessible_error_messages', $checklist);
        $this->assertArrayHasKey('mobile_safe_layouts', $checklist);
        $this->assertArrayHasKey('clear_status_indicators', $checklist);
    }

    public function test_is_complete_is_true_only_when_every_required_check_is_present(): void
    {
        $allKeys = array_keys($this->service->checklist());
        $allButOne = array_slice($allKeys, 0, 6);

        $this->assertFalse($this->service->isComplete([]));
        $this->assertFalse($this->service->isComplete($allButOne));
        $this->assertTrue($this->service->isComplete($allKeys));
    }

    public function test_missing_returns_the_checks_not_yet_confirmed(): void
    {
        $this->assertSame(
            array_keys($this->service->checklist()),
            $this->service->missing([]),
        );

        $this->assertSame(['visible_focus_states'], $this->service->missing(
            array_values(array_diff(array_keys($this->service->checklist()), ['visible_focus_states'])),
        ));

        $this->assertEmpty($this->service->missing(array_keys($this->service->checklist())));
    }

    public function test_extra_unknown_confirmed_keys_do_not_break_completeness(): void
    {
        $allPlusExtra = array_merge(array_keys($this->service->checklist()), ['something_else']);

        $this->assertTrue($this->service->isComplete($allPlusExtra));
        $this->assertEmpty($this->service->missing($allPlusExtra));
    }
}
