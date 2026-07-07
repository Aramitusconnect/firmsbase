<?php

namespace Tests\Feature\Governance\MarketReadyValueMultipliers;

use App\Enums\GovernanceMappingStatus;
use App\Services\TemplatePackCoverageMappingService;
use Tests\TestCase;

class TemplatePackCoverageMappingServiceTest extends TestCase
{
    private const REQUIRED_PACK_KEYS = [
        'immigration_starter_pack',
        'immigration_advanced_pack',
        'family_law_pack',
        'criminal_defense_pack',
        'estate_planning_pack',
        'personal_injury_pack',
        'business_law_pack',
        'real_estate_pack',
        'law_firm_intake_pack',
        'legal_specialist_pack',
    ];

    private const REQUIRED_COMMERCIAL_KEYS = [
        'included_by_plan',
        'sold_as_addon',
        'bundled_into_implementation_services',
    ];

    private TemplatePackCoverageMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TemplatePackCoverageMappingService();
    }

    public function test_all_ten_pack_keys_and_three_commercial_keys_are_declared_explicitly(): void
    {
        $packs = $this->service->packs();
        $commercial = $this->service->commercialModels();

        $this->assertCount(10, $packs);
        $this->assertCount(3, $commercial);
        $this->assertCount(13, $this->service->all());

        $declaredPackKeys = array_map(fn ($item) => $item->item_key, $packs);
        $declaredCommercialKeys = array_map(fn ($item) => $item->item_key, $commercial);

        foreach (self::REQUIRED_PACK_KEYS as $key) {
            $this->assertContains($key, $declaredPackKeys, "Missing required pack key: {$key}");
        }

        foreach (self::REQUIRED_COMMERCIAL_KEYS as $key) {
            $this->assertContains($key, $declaredCommercialKeys, "Missing required commercial key: {$key}");
        }
    }

    public function test_no_duplicate_keys_exist(): void
    {
        $keys = array_map(fn ($item) => $item->item_key, $this->service->all());

        $this->assertCount(count($keys), array_unique($keys), 'Duplicate template-pack key(s) found.');
    }

    public function test_family_law_and_personal_injury_are_not_found_and_trust_gated(): void
    {
        $familyLaw = $this->service->byKey('family_law_pack');
        $personalInjury = $this->service->byKey('personal_injury_pack');

        $this->assertSame(GovernanceMappingStatus::NotFound, $familyLaw->status);
        $this->assertSame(GovernanceMappingStatus::NotFound, $personalInjury->status);
        $this->assertStringContainsString('trust', strtolower($familyLaw->notes));
        $this->assertStringContainsString('trust', strtolower($personalInjury->notes));
    }

    public function test_no_trust_dependent_pack_is_classified_as_launched(): void
    {
        foreach ($this->service->trustDependent() as $item) {
            $this->assertNotSame(GovernanceMappingStatus::Implemented, $item->status);
        }
    }

    public function test_trust_dependent_accessor_returns_exactly_family_law_and_personal_injury(): void
    {
        $keys = array_map(fn ($item) => $item->item_key, $this->service->trustDependent());
        sort($keys);

        $this->assertSame(['family_law_pack', 'personal_injury_pack'], $keys);
    }

    public function test_all_other_unbuilt_packs_are_not_found(): void
    {
        foreach (['criminal_defense_pack', 'estate_planning_pack', 'business_law_pack', 'real_estate_pack', 'law_firm_intake_pack'] as $key) {
            $this->assertSame(GovernanceMappingStatus::NotFound, $this->service->byKey($key)->status, "{$key} should be NotFound.");
        }
    }

    public function test_legal_specialist_pack_notes_mention_the_boundary_policy_service(): void
    {
        $item = $this->service->byKey('legal_specialist_pack');

        $this->assertSame(GovernanceMappingStatus::NotFound, $item->status);
        $this->assertStringContainsString('LegalSpecialistBoundaryPolicyService', $item->notes);
    }

    public function test_commercial_differentiation_is_not_falsely_marked_complete(): void
    {
        $includedByPlan = $this->service->byKey('included_by_plan');
        $soldAsAddon = $this->service->byKey('sold_as_addon');
        $bundled = $this->service->byKey('bundled_into_implementation_services');

        $this->assertNotSame(GovernanceMappingStatus::Implemented, $includedByPlan->status);
        $this->assertSame(GovernanceMappingStatus::NotFound, $soldAsAddon->status);
        $this->assertSame(GovernanceMappingStatus::NotFound, $bundled->status);
        $this->assertStringContainsString('blanket', $includedByPlan->notes);
    }

    public function test_every_mapping_has_evidence_or_notes(): void
    {
        foreach ($this->service->all() as $item) {
            $this->assertNotEmpty($item->notes, "Item {$item->item_key} should have explanatory notes.");
        }
    }

    public function test_byKey_returns_null_for_an_unknown_key(): void
    {
        $this->assertNull($this->service->byKey('does_not_exist'));
    }
}
