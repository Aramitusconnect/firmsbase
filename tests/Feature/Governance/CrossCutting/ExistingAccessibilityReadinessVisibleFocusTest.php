<?php

namespace Tests\Feature\Governance\CrossCutting;

use App\Services\BillingAccessibilityReadinessService;
use App\Services\FormAccessibilityReadinessService;
use App\Services\SignatureAccessibilityReadinessService;
use Tests\TestCase;

/**
 * Regression test: proves the cross-cutting package's visible_focus_states
 * addition landed on all three pre-existing accessibility readiness
 * services and did not remove any of their prior required checks.
 */
class ExistingAccessibilityReadinessVisibleFocusTest extends TestCase
{
    public function test_billing_accessibility_readiness_includes_visible_focus_states(): void
    {
        $checklist = (new BillingAccessibilityReadinessService())->checklist();

        $this->assertArrayHasKey('visible_focus_states', $checklist);
        $this->assertArrayHasKey('keyboard_accessible_flow', $checklist);
        $this->assertArrayHasKey('readable_errors', $checklist);
        $this->assertArrayHasKey('accessible_status_labels', $checklist);
        $this->assertArrayHasKey('readable_contrast', $checklist);
        $this->assertArrayHasKey('mobile_safe_layout', $checklist);
    }

    public function test_form_accessibility_readiness_includes_visible_focus_states(): void
    {
        $checklist = (new FormAccessibilityReadinessService())->checklist();

        $this->assertArrayHasKey('visible_focus_states', $checklist);
        $this->assertArrayHasKey('keyboard_accessible_actions', $checklist);
        $this->assertArrayHasKey('accessible_labels', $checklist);
        $this->assertArrayHasKey('readable_validation_errors', $checklist);
        $this->assertArrayHasKey('accessible_checklist_controls', $checklist);
    }

    public function test_signature_accessibility_readiness_includes_visible_focus_states(): void
    {
        $checklist = (new SignatureAccessibilityReadinessService())->checklist();

        $this->assertArrayHasKey('visible_focus_states', $checklist);
        $this->assertArrayHasKey('keyboard_accessible_signing', $checklist);
        $this->assertArrayHasKey('clear_consent_presentation', $checklist);
        $this->assertArrayHasKey('readable_validation_errors', $checklist);
        $this->assertArrayHasKey('accessible_signature_controls', $checklist);
        $this->assertArrayHasKey('mobile_safe_execution', $checklist);
    }
}
