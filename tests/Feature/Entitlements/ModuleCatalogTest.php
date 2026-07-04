<?php

namespace Tests\Feature\Entitlements;

use App\Models\ModuleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_via_factory(): void
    {
        $module = ModuleCatalog::factory()->create();

        $this->assertDatabaseHas('module_catalog', ['id' => $module->id]);
    }

    public function test_module_code_is_unique(): void
    {
        ModuleCatalog::factory()->create(['module_code' => 'immigration_core']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        ModuleCatalog::factory()->create(['module_code' => 'immigration_core']);
    }

    public function test_route_key_name_is_module_code(): void
    {
        $module = new ModuleCatalog();

        $this->assertSame('module_code', $module->getRouteKeyName());
    }

    public function test_no_uuid_column_exists(): void
    {
        $module = ModuleCatalog::factory()->create();

        $this->assertArrayNotHasKey('uuid', $module->getAttributes());
    }
}
