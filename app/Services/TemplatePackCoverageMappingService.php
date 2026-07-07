<?php

namespace App\Services;

use App\Enums\GovernanceMappingStatus;
use App\ValueObjects\GovernanceMappingResult;

/**
 * TemplatePackCoverageMappingService — declares the master plan's
 * Section 30 premium template pack catalog (10 practice-area pack keys
 * plus 3 commercial-model keys) and maps each to an EXISTING owning
 * mechanism or a known, honestly-classified gap. Purely declarative —
 * no new pack, seed data, or commercial mechanism is created here or
 * anywhere else in this section. Reuses GovernanceMappingResult/
 * GovernanceMappingStatus from the Section 25-29 cross-cutting
 * package.
 *
 * Every classification below was determined by direct inspection of
 * the real repository at the time this service was written: TemplatePack/
 * TemplatePackVersion/InstalledTemplatePack are a real, generic
 * catalog/install mechanism, but pack_code is a plain string with no
 * seeded practice-area-specific rows anywhere in database/seeders or
 * database/factories except the generic random-slug factory. The only
 * practice area with concrete, practice-specific supporting evidence
 * is immigration (ImmigrationFormCode enum's exact 7 approved starter
 * forms, validated by FormTemplateService::registerFormCode(), plus
 * PracticeAreaFactory::immigration()).
 */
class TemplatePackCoverageMappingService
{
    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function all(): array
    {
        return array_merge($this->packs(), $this->commercialModels());
    }

    public function byKey(string $key): ?GovernanceMappingResult
    {
        foreach ($this->all() as $item) {
            if ($item->item_key === $key) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function packs(): array
    {
        return [
            new GovernanceMappingResult(
                item_key: 'immigration_starter_pack',
                item_label: 'Immigration starter template pack',
                owning_class: \App\Enums\ImmigrationFormCode::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'ImmigrationFormCode declares the exact 7 approved starter forms and FormTemplateService::registerFormCode() validates against it; PracticeAreaFactory::immigration() gives immigration a named practice-area state. However, no seeder or factory creates a concrete TemplatePack/TemplatePackVersion catalog row with a real "immigration_starter" pack_code — TemplatePackFactory only ever generates a random slug. The supporting form-code infrastructure is real and immigration-specific, but no persisted catalog entry proves the pack itself has been assembled and published as a sellable artifact.',
            ),
            new GovernanceMappingResult(
                item_key: 'immigration_advanced_pack',
                item_label: 'Immigration advanced template pack',
                owning_class: \App\Models\TemplatePack::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'TemplatePack/TemplatePackVersion is a real, generic catalog/versioning mechanism capable of holding an "advanced" pack, but nothing in ImmigrationFormCode or elsewhere distinguishes an advanced tier from the starter tier — the same 7 form codes are the entire declared immigration surface. No advanced-specific content, seed data, or service exists.',
            ),
            new GovernanceMappingResult(
                item_key: 'family_law_pack',
                item_label: 'Family Law template pack',
                owning_class: \App\Services\TrustPilotExitCriteriaService::class,
                status: GovernanceMappingStatus::NotFound,
                notes: 'No Family Law pack, seed data, or practice-area content exists anywhere (confirmed by direct search). This is intentionally trust-gated: TrustDependentPackGatingMappingService documents that a trust-dependent pack must not launch before TrustPilotExitCriteriaService\'s exit criteria are met, so this is a deliberate non-launch, not a coding gap.',
            ),
            new GovernanceMappingResult(
                item_key: 'personal_injury_pack',
                item_label: 'Personal Injury template pack',
                owning_class: \App\Services\TrustPilotExitCriteriaService::class,
                status: GovernanceMappingStatus::NotFound,
                notes: 'No Personal Injury pack, seed data, or practice-area content exists anywhere (confirmed by direct search). Intentionally trust-gated for the same reason as family_law_pack — a deliberate non-launch pending the trust-pilot exit criteria, not a coding gap.',
            ),
            new GovernanceMappingResult(
                item_key: 'criminal_defense_pack',
                item_label: 'Criminal Defense template pack',
                owning_class: null,
                status: GovernanceMappingStatus::NotFound,
                notes: 'No Criminal Defense pack, seed data, form codes, or practice-area content exists anywhere (confirmed by direct search). Intentionally unbuilt future product feature, not a gap.',
            ),
            new GovernanceMappingResult(
                item_key: 'estate_planning_pack',
                item_label: 'Estate Planning template pack',
                owning_class: null,
                status: GovernanceMappingStatus::NotFound,
                notes: 'No Estate Planning pack, seed data, form codes, or practice-area content exists anywhere (confirmed by direct search). Intentionally unbuilt future product feature, not a gap.',
            ),
            new GovernanceMappingResult(
                item_key: 'business_law_pack',
                item_label: 'Business Law template pack',
                owning_class: null,
                status: GovernanceMappingStatus::NotFound,
                notes: 'No Business Law pack, seed data, form codes, or practice-area content exists anywhere (confirmed by direct search). Intentionally unbuilt future product feature, not a gap.',
            ),
            new GovernanceMappingResult(
                item_key: 'real_estate_pack',
                item_label: 'Real Estate template pack',
                owning_class: null,
                status: GovernanceMappingStatus::NotFound,
                notes: 'No Real Estate pack, seed data, form codes, or practice-area content exists anywhere (confirmed by direct search). Intentionally unbuilt future product feature, not a gap.',
            ),
            new GovernanceMappingResult(
                item_key: 'law_firm_intake_pack',
                item_label: 'General law firm intake template pack',
                owning_class: null,
                status: GovernanceMappingStatus::NotFound,
                notes: 'No general law-firm-intake pack, seed data, or dedicated content exists anywhere (confirmed by direct search), distinct from the generic IntakeSubmission mechanism which is practice-area-agnostic and not itself a template pack. Intentionally unbuilt future product feature, not a gap.',
            ),
            new GovernanceMappingResult(
                item_key: 'legal_specialist_pack',
                item_label: 'Legal Specialist customer-type template pack',
                owning_class: \App\Services\LegalSpecialistBoundaryPolicyService::class,
                status: GovernanceMappingStatus::NotFound,
                notes: 'No pack, seed data, or catalog entry targeted at CustomerType::LegalSpecialist exists anywhere (confirmed by direct search). LegalSpecialistBoundaryPolicyService is real and enforces that legal_specialist firms never see trust/IOLTA or law-firm-only terminology, but that is a boundary/terminology policy, not a template pack — no legal-specialist-specific pack content exists to classify as launched.',
            ),
        ];
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function commercialModels(): array
    {
        return [
            new GovernanceMappingResult(
                item_key: 'included_by_plan',
                item_label: 'Template packs included by plan tier',
                owning_class: \App\Services\TemplatePackCommercialService::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'TemplatePackCommercialService::installIfEntitled() is a real, tested gate wrapping TemplatePackInstallationService with a single blanket "practice_area_templates" module entitlement resolved via EntitlementService. This proves plan-tier gating exists at the module level, but it is all-or-nothing across every pack — there is no per-pack entitlement, tier, or pricing differentiation of any kind. See the template_pack_per_pack_commercial_differentiation_missing gap.',
            ),
            new GovernanceMappingResult(
                item_key: 'sold_as_addon',
                item_label: 'Template packs sold as a standalone add-on purchase',
                owning_class: null,
                status: GovernanceMappingStatus::NotFound,
                notes: 'No add-on purchase, one-time-sale, or per-pack pricing mechanism exists anywhere (confirmed by direct search) — the only commercial gate is the single blanket practice_area_templates entitlement. Intentionally unbuilt future commercialization feature, not a gap this package can close beyond what is already registered.',
            ),
            new GovernanceMappingResult(
                item_key: 'bundled_into_implementation_services',
                item_label: 'Template packs bundled into a paid implementation-services engagement',
                owning_class: null,
                status: GovernanceMappingStatus::NotFound,
                notes: 'No implementation-services, professional-services, or bundling concept exists anywhere in the repository (confirmed by direct search). Intentionally unbuilt future commercialization feature.',
            ),
        ];
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function trustDependent(): array
    {
        return array_values(array_filter(
            $this->packs(),
            fn (GovernanceMappingResult $item) => in_array($item->item_key, ['family_law_pack', 'personal_injury_pack'], true),
        ));
    }
}
