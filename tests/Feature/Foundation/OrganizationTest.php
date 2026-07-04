<?php

namespace Tests\Feature\Foundation;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_via_factory(): void
    {
        $organization = Organization::factory()->create();

        $this->assertDatabaseHas('organizations', ['id' => $organization->id]);
    }

    public function test_uuid_is_generated_and_immutable(): void
    {
        $organization = Organization::factory()->create();

        $this->assertNotEmpty($organization->uuid);

        $this->expectException(\LogicException::class);
        $organization->uuid = (string) \Illuminate\Support\Str::uuid7();
        $organization->save();
    }
}
