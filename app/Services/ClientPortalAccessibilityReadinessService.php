<?php

namespace App\Services;

/**
 * ClientPortalAccessibilityReadinessService — backend-only WCAG
 * readiness checklist metadata for the client portal surface (no
 * client portal UI exists yet in this repo). Direct structural mirror
 * of BillingAccessibilityReadinessService/FormAccessibilityReadinessService/
 * SignatureAccessibilityReadinessService — documentation-as-code, so a
 * future phase that DOES build the client portal UI has a concrete,
 * testable checklist to satisfy.
 */
class ClientPortalAccessibilityReadinessService
{
    public const REQUIRED_CHECKS = [
        'keyboard_navigation' => 'Every client portal screen and action must be fully navigable via keyboard alone.',
        'visible_focus_states' => 'Every focusable element must have a visible, unambiguous focus indicator.',
        'readable_contrast' => 'Text and controls must meet readable contrast requirements.',
        'labels' => 'Every field and control must have an accessible, programmatically-associated label.',
        'accessible_error_messages' => 'Error messages must be readable and understandable, not just color-coded.',
        'mobile_safe_layouts' => 'Layouts must be mobile-safe.',
        'clear_status_indicators' => 'Status indicators must be accessible to screen readers, not conveyed by color/icon alone.',
    ];

    /**
     * @return array<string, string>
     */
    public function checklist(): array
    {
        return self::REQUIRED_CHECKS;
    }

    /**
     * @param array<string> $completedChecks the check keys a reviewer has confirmed
     */
    public function isComplete(array $completedChecks): bool
    {
        return empty($this->missing($completedChecks));
    }

    /**
     * @param array<string> $completedChecks the check keys a reviewer has confirmed
     * @return array<int, string> the required check keys not yet confirmed
     */
    public function missing(array $completedChecks): array
    {
        return array_values(array_diff(array_keys(self::REQUIRED_CHECKS), $completedChecks));
    }
}
