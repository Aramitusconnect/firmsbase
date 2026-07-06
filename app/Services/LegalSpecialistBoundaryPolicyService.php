<?php

namespace App\Services;

use App\Enums\CustomerType;
use App\Models\Firm;

/**
 * LegalSpecialistBoundaryPolicyService — enforces the existing project
 * rule (legal_specialist customers must not see trust/IOLTA workflows
 * or law-firm-only terminology) specifically across Phase 16's own new
 * output surfaces (license file content, deployment config labels,
 * health check detail strings). No new terminology-mapping/rendering
 * system is introduced — Phase 16 has no UI of its own, so this
 * service's job is a policy assertion plus the forbidden-terms list a
 * firewall test checks Phase 16 output strings against.
 */
class LegalSpecialistBoundaryPolicyService
{
    private const FORBIDDEN_TERMS = [
        'trust account', 'IOLTA', 'trust ledger', 'trust accounting',
        'law firm', 'attorney', 'client trust',
    ];

    public function isLegalSpecialist(Firm $firm): bool
    {
        return $firm->customer_type === CustomerType::LegalSpecialist;
    }

    /**
     * A legal_specialist firm may never have trust/IOLTA protection
     * enabled — this is a read-only assertion, never a writer of
     * firm_settings.trust_iolta_protection (Phase 1/13 own that
     * column).
     */
    public function assertTrustIoltaNeverEnabledFor(Firm $firm): void
    {
        if (! $this->isLegalSpecialist($firm)) {
            return;
        }

        if ((bool) ($firm->firmSettings?->trust_iolta_protection ?? false) === true) {
            throw new \RuntimeException('legal_specialist firms must never have trust/IOLTA protection enabled.');
        }
    }

    /**
     * Used by firewall tests: asserts a piece of Phase 16 output text
     * (a license file label, deployment config detail, health check
     * message) contains none of the forbidden trust/IOLTA/law-firm-only
     * terms, case-insensitively.
     */
    public function containsForbiddenTerminology(string $text): bool
    {
        $normalized = strtolower($text);

        foreach (self::FORBIDDEN_TERMS as $term) {
            if (str_contains($normalized, strtolower($term))) {
                return true;
            }
        }

        return false;
    }

    public function assertBoundarySafeOutput(Firm $firm, string $text): void
    {
        if (! $this->isLegalSpecialist($firm)) {
            return;
        }

        if ($this->containsForbiddenTerminology($text)) {
            throw new \RuntimeException('Output for a legal_specialist firm must not contain trust/IOLTA/law-firm-only terminology.');
        }
    }
}
