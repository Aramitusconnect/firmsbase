<?php

namespace Tests\Feature\Governance\MarketReadyValueMultipliers;

use App\Services\TrustDependentPackGatingMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrustDependentPackGatingMappingServiceTest extends TestCase
{
    use RefreshDatabase;

    private TrustDependentPackGatingMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TrustDependentPackGatingMappingService();
    }

    public function test_family_law_and_personal_injury_require_trust_pilot_exit(): void
    {
        $this->assertTrue($this->service->requiresTrustPilotExit('family_law_pack'));
        $this->assertTrue($this->service->requiresTrustPilotExit('personal_injury_pack'));
    }

    public function test_immigration_starter_and_advanced_do_not_require_trust_pilot_exit(): void
    {
        $this->assertFalse($this->service->requiresTrustPilotExit('immigration_starter_pack'));
        $this->assertFalse($this->service->requiresTrustPilotExit('immigration_advanced_pack'));
    }

    public function test_all_named_packs_are_covered(): void
    {
        $expected = [
            'immigration_starter_pack', 'immigration_advanced_pack', 'family_law_pack',
            'criminal_defense_pack', 'estate_planning_pack', 'personal_injury_pack',
            'business_law_pack', 'real_estate_pack', 'law_firm_intake_pack', 'legal_specialist_pack',
        ];

        $declared = array_map(fn ($item) => $item->item_key, $this->service->all());
        sort($expected);
        sort($declared);

        $this->assertSame($expected, $declared);
    }

    public function test_no_duplicate_keys_exist(): void
    {
        $keys = array_map(fn ($item) => $item->item_key, $this->service->all());

        $this->assertCount(count($keys), array_unique($keys), 'Duplicate pack key(s) found.');
    }

    public function test_trust_dependent_packs_accessor_returns_exactly_two_packs(): void
    {
        $keys = array_map(fn ($item) => $item->item_key, $this->service->trustDependentPacks());
        sort($keys);

        $this->assertSame(['family_law_pack', 'personal_injury_pack'], $keys);
    }

    public function test_non_trust_dependent_packs_accessor_returns_the_remaining_eight_packs(): void
    {
        $this->assertCount(8, $this->service->nonTrustDependentPacks());
    }

    public function test_trust_pilot_exit_criteria_service_is_the_owning_evidence_for_trust_dependent_packs(): void
    {
        foreach ($this->service->trustDependentPacks() as $item) {
            $this->assertSame(\App\Services\TrustPilotExitCriteriaService::class, $item->owning_class);
        }
    }

    public function test_service_gates_nothing_itself_and_creates_no_pack_records(): void
    {
        $countBefore = \App\Models\TemplatePack::count();

        $this->service->requiresTrustPilotExit('family_law_pack');
        $this->service->all();

        $this->assertSame($countBefore, \App\Models\TemplatePack::count());
    }

    public function test_byPack_returns_null_for_an_unknown_key(): void
    {
        $this->assertNull($this->service->byPack('does_not_exist'));
    }
}
