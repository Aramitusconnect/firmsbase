<?php

namespace App\Services;

/**
 * SignatureAccessibilityReadinessService — backend-only WCAG + mobile
 * signing readiness checklist (project rule: no UI is being built in
 * this phase). Direct structural mirror of Phase 6/10's
 * BillingAccessibilityReadinessService/FormAccessibilityReadinessService
 * — documentation-as-code, so a future phase that DOES build the real
 * signing UI has a concrete, testable checklist to satisfy. Matches the
 * master plan verbatim: "Signature screens must pass WCAG checks:
 * keyboard-accessible signing, clear consent, readable errors,
 * accessible signature controls, and mobile-safe execution."
 */
class SignatureAccessibilityReadinessService
{
    public const REQUIRED_CHECKS = [
        'keyboard_accessible_signing' => 'Every signing action must be fully keyboard-accessible.',
        'clear_consent_presentation' => 'Consent text and the act of giving consent must be clearly, unambiguously presented.',
        'readable_validation_errors' => 'Signing and consent validation errors must be readable and understandable, not just color-coded.',
        'accessible_signature_controls' => 'Signature controls must be individually labeled and operable via keyboard/screen reader.',
        'mobile_safe_execution' => 'The signing flow must be safely completable on mobile devices.',
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
