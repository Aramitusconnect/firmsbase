<?php

namespace Tests\Feature\PracticeTemplates;

use App\Models\PracticeArea;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PracticeAreaTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_via_factory(): void
    {
        $area = PracticeArea::factory()->create();

        $this->assertDatabaseHas('practice_areas', ['id' => $area->id]);
    }

    public function test_no_firm_id_column_exists(): void
    {
        $area = PracticeArea::factory()->create();

        $this->assertArrayNotHasKey('firm_id', $area->getAttributes());
    }

    public function test_no_uuid_column_exists(): void
    {
        $area = PracticeArea::factory()->create();

        $this->assertArrayNotHasKey('uuid', $area->getAttributes());
    }

    public function test_route_key_name_is_code(): void
    {
        $area = new PracticeArea;

        $this->assertSame('code', $area->getRouteKeyName());
    }

    public function test_code_is_unique(): void
    {
        // Mission 2 (MyAttorney Marketplace Core), checkpoint 2's own
        // marketplace practice-area catalog migration
        // (2026_11_10_100011_seed_marketplace_practice_area_catalog.php)
        // permanently seeds a real 'immigration' row into every
        // migrated database — this test's own fixture code must not
        // collide with that real, permanent catalog entry (it
        // previously did, causing the very first create() below to
        // throw before expectException() was ever engaged for it).
        PracticeArea::factory()->create(['code' => 'test-duplicate-code-fixture']);

        $this->expectException(QueryException::class);

        PracticeArea::factory()->create(['code' => 'test-duplicate-code-fixture']);
    }
}
