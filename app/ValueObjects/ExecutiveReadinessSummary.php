<?php

namespace App\ValueObjects;

/**
 * ExecutiveReadinessSummary — the final Section 31 synthesis result
 * returned by FinalExecutiveReadinessMappingService::summary(). Pure
 * data: no DB calls, no service calls, no writes. Every matrix field
 * holds an array of GovernanceMappingResult; knownOpenGaps holds the
 * current ComplianceGapRegistryService::all() output verbatim (no
 * second gap register is created).
 */
final readonly class ExecutiveReadinessSummary
{
    /**
     * @param  array<int, GovernanceMappingResult>  $pilotLaunchReadiness
     * @param  array<int, GovernanceMappingResult>  $architecturePreservation
     * @param  array<int, GovernanceMappingResult>  $workflowAutomationDifferentiation
     * @param  array<int, GovernanceMappingResult>  $structuralCommitments
     * @param  array<int, GovernanceMappingResult>  $oneProductNoForkStrategy
     * @param  array<int, GapRegisterItem>  $knownOpenGaps
     * @param  array<int, GapRegisterItem>  $dedicatedPrivateDealBlockers
     */
    public function __construct(
        public array $pilotLaunchReadiness,
        public array $architecturePreservation,
        public array $workflowAutomationDifferentiation,
        public array $structuralCommitments,
        public array $oneProductNoForkStrategy,
        public array $knownOpenGaps,
        public array $dedicatedPrivateDealBlockers,
    ) {
    }
}
