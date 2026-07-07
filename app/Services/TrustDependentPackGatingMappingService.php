<?php

namespace App\Services;

use App\Enums\GovernanceMappingStatus;
use App\ValueObjects\GovernanceMappingResult;

/**
 * TrustDependentPackGatingMappingService — declares which premium
 * template packs are trust-dependent (must not launch before the
 * existing TrustPilotExitCriteriaService's exit criteria are met) and
 * which are not. This service gates nothing itself: it does not
 * activate trust mode, does not mark the trust pilot complete, does
 * not create pack seed data, and does not enforce any
 * install/sell restriction — it is a purely declarative mapping
 * reusing TrustPilotExitCriteriaService as the sole owning evidence
 * for the trust-dependent classification.
 */
class TrustDependentPackGatingMappingService
{
    private const TRUST_DEPENDENT_PACK_KEYS = [
        'family_law_pack',
        'personal_injury_pack',
    ];

    private const NON_TRUST_DEPENDENT_PACK_KEYS = [
        'immigration_starter_pack',
        'immigration_advanced_pack',
        'criminal_defense_pack',
        'estate_planning_pack',
        'business_law_pack',
        'real_estate_pack',
        'law_firm_intake_pack',
        'legal_specialist_pack',
    ];

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function all(): array
    {
        return array_merge($this->trustDependentPacks(), $this->nonTrustDependentPacks());
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function trustDependentPacks(): array
    {
        return array_map(
            fn (string $key) => new GovernanceMappingResult(
                item_key: $key,
                item_label: $this->labelFor($key),
                owning_class: \App\Services\TrustPilotExitCriteriaService::class,
                status: GovernanceMappingStatus::NotApplicableYet,
                notes: "This pack is trust-dependent: it must not launch until TrustPilotExitCriteriaService::EXIT_CRITERIA is satisfied and reviewed for this practice area. TrustPilotExitCriteriaService::checklistFor() is read-only reporting — it gates_anything_automatically = false — so no automatic activation path exists. This mapping does not create pack seed data, does not activate trust mode, and does not itself enforce any gate.",
            ),
            self::TRUST_DEPENDENT_PACK_KEYS,
        );
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function nonTrustDependentPacks(): array
    {
        return array_map(
            fn (string $key) => new GovernanceMappingResult(
                item_key: $key,
                item_label: $this->labelFor($key),
                owning_class: \App\Services\TrustPilotExitCriteriaService::class,
                status: GovernanceMappingStatus::NotApplicableYet,
                notes: 'This pack does not depend on trust/IOLTA workflows and therefore does not require TrustPilotExitCriteriaService exit criteria to be met before it could launch. See TemplatePackCoverageMappingService for this pack\'s actual build-out status.',
            ),
            self::NON_TRUST_DEPENDENT_PACK_KEYS,
        );
    }

    public function byPack(string $packKey): ?GovernanceMappingResult
    {
        foreach ($this->all() as $item) {
            if ($item->item_key === $packKey) {
                return $item;
            }
        }

        return null;
    }

    public function requiresTrustPilotExit(string $packKey): bool
    {
        return in_array($packKey, self::TRUST_DEPENDENT_PACK_KEYS, true);
    }

    private function labelFor(string $key): string
    {
        return match ($key) {
            'immigration_starter_pack' => 'Immigration starter template pack',
            'immigration_advanced_pack' => 'Immigration advanced template pack',
            'family_law_pack' => 'Family Law template pack',
            'criminal_defense_pack' => 'Criminal Defense template pack',
            'estate_planning_pack' => 'Estate Planning template pack',
            'personal_injury_pack' => 'Personal Injury template pack',
            'business_law_pack' => 'Business Law template pack',
            'real_estate_pack' => 'Real Estate template pack',
            'law_firm_intake_pack' => 'General law firm intake template pack',
            'legal_specialist_pack' => 'Legal Specialist customer-type template pack',
            default => $key,
        };
    }
}
