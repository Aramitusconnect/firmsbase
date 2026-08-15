<?php

declare(strict_types=1);

namespace App\Services\Configuration;

use App\Models\PracticeArea;
use RuntimeException;

/**
 * PracticeAreaMergeProposalService — builds the full evidence package
 * mission section 36 requires BEFORE any practice-area merge could ever
 * be considered, and is structurally incapable of performing one.
 *
 * There is deliberately NO execute()/merge()/reassign() method on this
 * class, and no such method exists anywhere else in the codebase (no
 * canonical practice-area merge service has ever been written — see the
 * final report's discovery matrix). Section 36 is unconditional:
 * real existing-data merge execution requires separate explicit owner
 * approval, and that remains true even when duplicate evidence is
 * strong and no schema change would be needed. Rather than writing an
 * execution path and guarding it with a flag someone could later flip
 * by accident, the capability simply does not exist here — the only
 * way to add it is a deliberate future code change, which is exactly
 * the review checkpoint the owner-approval gate is meant to create.
 *
 * assertMergeExecutionNotPermitted() exists so that intent is
 * executable rather than merely documented: any future caller that
 * tries to route a real merge through this service fails loudly, and
 * the mission's own regression test asserts that failure.
 */
class PracticeAreaMergeProposalService
{
    public function __construct(
        private readonly PracticeAreaCanonicalizationService $canonicalization,
        private readonly PracticeAreaDependencyAnalysisService $dependencies,
    ) {}

    /**
     * The section 36 report for one candidate pair. Pure analysis — it
     * reads, compares and returns; it writes nothing.
     *
     * SEMANTICALLY_IDENTICAL is always reported as UNCERTAIN. This
     * service compares strings and counts rows; neither can establish
     * that two practice areas denote the same legal concept (section
     * 29's "Business Law" vs "Business / Corporate Law"). Asserting
     * YES here would manufacture exactly the false confidence the
     * owner-approval gate exists to prevent, so that determination is
     * left to the human reviewing this package.
     *
     * @param  bool  $scanTenantScoped  When false, tenant-owned dependency counts are reported as unavailable rather than scanned (see PracticeAreaDependencyAnalysisService).
     * @return array<string, mixed>
     */
    public function buildProposal(
        PracticeArea $source,
        PracticeArea $target,
        bool $scanTenantScoped = false,
    ): array {
        if ($source->id === $target->id) {
            throw new RuntimeException('A practice area cannot be merged into itself.');
        }

        $evidence = $this->canonicalization
            ->duplicateCandidatesFor(
                $source->name,
                $source->code,
                $source->slug,
                $this->canonicalization->aliasesOf($source),
                excludingId: $source->id,
            )
            ->first(fn ($candidate) => $candidate->practiceArea->id === $target->id);

        return [
            'source' => $this->identity($source),
            'target' => $this->identity($target),
            'duplicate_evidence' => $evidence?->matchReasons ?? [],
            'evidence_strength' => $evidence === null
                ? 'INSUFFICIENT_EVIDENCE'
                : 'SUSPECTED_DUPLICATE',
            'semantically_identical' => 'UNCERTAIN',
            'semantically_identical_note' => 'Not determinable by normalization or dependency analysis — requires human review of how each practice area is actually used.',
            'canonical_target' => $this->identity($target),
            'reason_for_canonical_target' => $this->canonicalTargetRationale($source, $target),
            'dependencies' => [
                'source' => $this->dependencySection($source, $scanTenantScoped),
                'target' => $this->dependencySection($target, $scanTenantScoped),
            ],
            'alias_redirect_behavior' => 'NOT_IMPLEMENTED — practice_areas.synonyms is stored but no resolver consults it, so merging would not automatically redirect the source\'s aliases.',
            'source_post_merge_state' => 'Would be deactivated (is_active = false), never hard-deleted — a practice area referenced by any historical row must remain a valid foreign-key target forever (PracticeAreaService).',
            'rollback_limitations' => 'No merge has been executed, so nothing requires rollback. Any future merge would rewrite practice_area_id on referencing rows; without a purpose-built reversal record that reassignment is not automatically reversible.',
            'merge_safe' => false,
            'merge_safe_reason' => 'No canonical practice-area merge service exists in this codebase, so the section 37 reference-preservation invariants cannot be proven.',
            'owner_approval_required' => true,
            'executed' => false,
        ];
    }

    /**
     * The hard stop. This service performs analysis only; there is no
     * execution path, and calling for one is always an error.
     */
    public function assertMergeExecutionNotPermitted(): never
    {
        throw new RuntimeException(
            'Practice area merge execution is not implemented and requires separate explicit owner approval '
            .'(mission section 36). This service builds merge proposals only.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function identity(PracticeArea $practiceArea): array
    {
        return [
            'id' => $practiceArea->id,
            'name' => $practiceArea->name,
            'code' => $practiceArea->code,
            'slug' => $practiceArea->slug,
            'is_active' => $practiceArea->is_active,
            'is_marketplace_visible' => $practiceArea->is_marketplace_visible,
            'aliases' => $this->canonicalization->aliasesOf($practiceArea),
            'created_at' => $practiceArea->created_at,
            'updated_at' => $practiceArea->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dependencySection(PracticeArea $practiceArea, bool $scanTenantScoped): array
    {
        $global = $this->dependencies->globalDependencies($practiceArea);

        if (! $scanTenantScoped) {
            return [
                'global' => $global,
                'tenant' => $this->dependencies->tenantDependenciesUnscanned(),
                'tenant_scanned' => false,
            ];
        }

        $scan = $this->dependencies->tenantDependenciesScanned($practiceArea);

        return [
            'global' => $global,
            'tenant' => $scan['rows'],
            'tenant_scanned' => true,
            'firms_scanned' => $scan['firmsScanned'],
            'firms_total' => $scan['firmsTotal'],
            'firms_affected' => $scan['firmsAffected'],
            'capped' => $scan['capped'],
        ];
    }

    private function canonicalTargetRationale(PracticeArea $source, PracticeArea $target): string
    {
        $sourceGlobal = collect($this->dependencies->globalDependencies($source))->sum('count');
        $targetGlobal = collect($this->dependencies->globalDependencies($target))->sum('count');

        $parts = [sprintf(
            'Target carries %d global reference(s); source carries %d.',
            $targetGlobal,
            $sourceGlobal,
        )];

        if ($target->is_active && ! $source->is_active) {
            $parts[] = 'Target is active while the source is already inactive.';
        }

        if ($target->is_marketplace_visible && ! $source->is_marketplace_visible) {
            $parts[] = 'Target is marketplace-visible while the source is not.';
        }

        $parts[] = 'Operator-selected — this rationale is descriptive evidence, not an automatic recommendation.';

        return implode(' ', $parts);
    }
}
