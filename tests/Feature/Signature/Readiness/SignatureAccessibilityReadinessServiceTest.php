<?php

namespace Tests\Feature\Signature\Readiness;

use App\Services\SignatureAccessibilityReadinessService;
use Tests\TestCase;

class SignatureAccessibilityReadinessServiceTest extends TestCase
{
    private SignatureAccessibilityReadinessService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SignatureAccessibilityReadinessService();
    }

    public function test_checklist_returns_the_five_required_wcag_mobile_checks(): void
    {
        $checklist = $this->service->checklist();

        $this->assertCount(5, $checklist);
        $this->assertArrayHasKey('keyboard_accessible_signing', $checklist);
        $this->assertArrayHasKey('clear_consent_presentation', $checklist);
        $this->assertArrayHasKey('readable_validation_errors', $checklist);
        $this->assertArrayHasKey('accessible_signature_controls', $checklist);
        $this->assertArrayHasKey('mobile_safe_execution', $checklist);
    }

    public function test_is_complete_requires_every_check_confirmed(): void
    {
        $this->assertFalse($this->service->isComplete(['keyboard_accessible_signing']));
        $this->assertTrue($this->service->isComplete(array_keys($this->service->checklist())));
    }
}
