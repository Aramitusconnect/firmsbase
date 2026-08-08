<?php

namespace Tests\Feature\PracticeTemplates;

use App\Models\Firm;
use App\Models\FirmPracticeArea;
use App\Models\PracticeArea;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Confirms the approved architecture: PracticeArea stays a global
 * catalog; a firm's enablement decision lives entirely in this join
 * table (mirrors FirmEntitlement from Phase 1).
 */
class FirmPracticeAreaTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_via_factory(): void
    {
        $join = FirmPracticeArea::factory()->create();

        $this->assertDatabaseHas('firm_practice_areas', ['id' => $join->id]);
    }

    public function test_a_firm_can_enable_multiple_practice_areas(): void
    {
        // FirmsVault staging follow-up ("Application Completion — Catalogs
        // + Firm-Owned Reference Data") added a real, seeded
        // practice_areas catalog (immigration_law/family_law/etc. among
        // its real codes) — these fixture codes are deliberately
        // synthetic ("fixture_*") so this test's own throwaway rows can
        // never collide with that authoritative seed data, now or as the
        // catalog grows.
        $firm = Firm::factory()->create();
        $areaOne = PracticeArea::factory()->create(['code' => 'fixture_practice_area_one']);
        $areaTwo = PracticeArea::factory()->create(['code' => 'fixture_practice_area_two']);

        FirmPracticeArea::factory()->forFirm($firm)->forPracticeArea($areaOne)->create();
        FirmPracticeArea::factory()->forFirm($firm)->forPracticeArea($areaTwo)->create();

        $this->assertSame(2, FirmPracticeArea::where('firm_id', $firm->id)->count());
    }

    public function test_unique_firm_practice_area_pair(): void
    {
        $firm = Firm::factory()->create();
        $area = PracticeArea::factory()->create();

        FirmPracticeArea::factory()->forFirm($firm)->forPracticeArea($area)->create();

        $this->expectException(QueryException::class);

        FirmPracticeArea::factory()->forFirm($firm)->forPracticeArea($area)->create();
    }
}
