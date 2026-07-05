<?php

namespace App\Services;

/**
 * FormAccessibilityReadinessService — backend-only WCAG readiness
 * checklist metadata for the legal PDF/form workflow (project rule: no
 * UI is being built in this phase). Direct structural mirror of Phase
 * 6's BillingAccessibilityReadinessService — documentation-as-code, so
 * a future phase that DOES build form/document UI has a concrete,
 * testable checklist to satisfy.
 */
class FormAccessibilityReadinessService
{
    public const REQUIRED_CHECKS = [
        'keyboard_accessible_actions' => 'Every form/document workflow action (generate, submit, approve, reject, archive) must be fully keyboard-accessible.',
        'accessible_labels' => 'Every form field and control must have an accessible, programmatically-associated label.',
        'readable_validation_errors' => 'Missing-data and validation errors must be readable and understandable, not just color-coded.',
        'accessible_checklist_controls' => 'Review checklist controls must be individually labeled and operable via keyboard/screen reader, not a single opaque flag.',
    ];

    /**
     * @return array<string, string>
     */
    public function checklist(): array
    {
        return self::REQUIRED_CHECKS;
    }

    /**
     * @param array<string> $confirmedChecks the check keys a reviewer has confirmed
     */
    public function isComplete(array $confirmedChecks): bool
    {
        return empty(array_diff(array_keys(self::REQUIRED_CHECKS), $confirmedChecks));
    }
}
