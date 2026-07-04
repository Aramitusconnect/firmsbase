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
        $firm = Firm::factory()->create();
        $immigration = PracticeArea::factory()->create(['code' => 'immigration']);
        $familyLaw = PracticeArea::factory()->create(['code' => 'family_law']);

        FirmPracticeArea::factory()->forFirm($firm)->forPracticeArea($immigration)->create();
        FirmPracticeArea::factory()->forFirm($firm)->forPracticeArea($familyLaw)->create();

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
