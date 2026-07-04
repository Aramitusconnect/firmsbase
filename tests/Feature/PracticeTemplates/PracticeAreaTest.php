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
        $area = new PracticeArea();

        $this->assertSame('code', $area->getRouteKeyName());
    }

    public function test_code_is_unique(): void
    {
        PracticeArea::factory()->create(['code' => 'immigration']);

        $this->expectException(QueryException::class);

        PracticeArea::factory()->create(['code' => 'immigration']);
    }
}
