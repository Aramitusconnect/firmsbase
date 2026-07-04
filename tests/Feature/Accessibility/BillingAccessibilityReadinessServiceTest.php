<?php

namespace Tests\Feature\Accessibility;

use App\Services\BillingAccessibilityReadinessService;
use Tests\TestCase;

class BillingAccessibilityReadinessServiceTest extends TestCase
{
    private BillingAccessibilityReadinessService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BillingAccessibilityReadinessService();
    }

    public function test_checklist_contains_all_five_required_wcag_checks(): void
    {
        $checklist = $this->service->checklist();

        $this->assertCount(5, $checklist);
        $this->assertArrayHasKey('keyboard_accessible_flow', $checklist);
        $this->assertArrayHasKey('readable_errors', $checklist);
        $this->assertArrayHasKey('accessible_status_labels', $checklist);
        $this->assertArrayHasKey('readable_contrast', $checklist);
        $this->assertArrayHasKey('mobile_safe_layout', $checklist);
    }

    public function test_is_complete_requires_every_check(): void
    {
        $allButOne = array_slice(array_keys(BillingAccessibilityReadinessService::REQUIRED_CHECKS), 0, 4);

        $this->assertFalse($this->service->isComplete($allButOne));
        $this->assertTrue($this->service->isComplete(array_keys(BillingAccessibilityReadinessService::REQUIRED_CHECKS)));
    }
}
