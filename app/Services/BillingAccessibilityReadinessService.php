<?php

namespace App\Services;

/**
 * BillingAccessibilityReadinessService — backend-only WCAG readiness
 * checklist metadata for payment/billing screens (project rule 14: "no
 * UI is being built unless the PDF explicitly requires it now" — it
 * does not for Phase 6). This is documentation-as-code: the exact 5
 * checks named in the PDF's Controls and Rules section, so a future
 * phase that DOES build billing UI has a concrete, testable checklist
 * to satisfy rather than a vague reminder.
 */
class BillingAccessibilityReadinessService
{
    public const REQUIRED_CHECKS = [
        'keyboard_accessible_flow' => 'Payment and billing flows must be fully keyboard-accessible.',
        'readable_errors' => 'Error messages must be readable and understandable, not just color-coded.',
        'accessible_status_labels' => 'Status labels (e.g. paid, past due) must be accessible to screen readers.',
        'readable_contrast' => 'Text and controls must meet readable contrast requirements.',
        'mobile_safe_layout' => 'Layouts must be mobile-safe.',
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
