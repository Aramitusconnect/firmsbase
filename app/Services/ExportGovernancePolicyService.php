<?php

namespace App\Services;

use App\Models\Firm;
use App\Services\LegalDataAccessPolicyService;
use App\ValueObjects\ExportGovernanceDecision;

/**
 * ExportGovernancePolicyService — wraps the EXISTING Phase 5
 * LegalDataAccessPolicyService::canExport() (untouched) and adds the
 * legal-hold/retention/offboarding checks this phase's scope requires.
 * No matter/document-level legal-hold or retention-policy table exists
 * yet in Phases 1-7 — these two checks are implemented as explicit
 * boolean parameters supplied by the caller (e.g. a future legal-hold
 * service) rather than invented tables, keeping this service a pure
 * policy composition layer.
 */
class ExportGovernancePolicyService
{
    public function __construct(
        private readonly LegalDataAccessPolicyService $legalDataAccessPolicyService,
    ) {
    }

    public function evaluate(
        Firm $firm,
        bool $hasActiveLegalHold = false,
        bool $retentionPeriodExpired = false,
        bool $firmIsOffboarding = false,
    ): ExportGovernanceDecision {
        if (! $this->legalDataAccessPolicyService->canExport($firm)) {
            return ExportGovernanceDecision::block('firm license status does not permit export');
        }

        if ($hasActiveLegalHold) {
            return ExportGovernanceDecision::block('an active legal hold blocks this export');
        }

        if ($retentionPeriodExpired) {
            return ExportGovernanceDecision::block('retention period has expired for the requested data');
        }

        if ($firmIsOffboarding) {
            // Offboarding does not BLOCK export — an offboarding firm
            // must still be able to export its own data (data must
            // never be destroyed or hidden, Phase 5 precedent). This
            // branch exists so callers can see offboarding was
            // evaluated (an OffboardingPackage export type exists
            // specifically for this case).
            return ExportGovernanceDecision::allow();
        }

        return ExportGovernanceDecision::allow();
    }
}
