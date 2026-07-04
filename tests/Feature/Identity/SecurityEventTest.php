<?php

namespace Tests\Feature\Identity;

use App\Models\SecurityEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SecurityEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_via_factory(): void
    {
        $event = SecurityEvent::factory()->create();

        $this->assertDatabaseHas('security_events', ['id' => $event->id]);
    }

    public function test_it_can_be_created_without_a_firm(): void
    {
        $event = SecurityEvent::factory()->create(['firm_id' => null]);

        $this->assertNull($event->firm_id);
    }

    public function test_no_updated_at_column_exists(): void
    {
        $event = SecurityEvent::factory()->create();

        $this->assertArrayNotHasKey('updated_at', $event->getAttributes());
    }

    public function test_no_uuid_column_exists(): void
    {
        $event = SecurityEvent::factory()->create();

        $this->assertArrayNotHasKey('uuid', $event->getAttributes());
    }

    public function test_indexes_exist_on_expected_columns(): void
    {
        $indexDefs = collect(DB::select(
            "select indexdef from pg_indexes where tablename = 'security_events'"
        ))->pluck('indexdef')->implode(' | ');

        $this->assertStringContainsString('firm_id', $indexDefs);
        $this->assertStringContainsString('actor_type', $indexDefs);
        $this->assertStringContainsString('actor_id', $indexDefs);
        $this->assertStringContainsString('event_type', $indexDefs);
        $this->assertStringContainsString('category', $indexDefs);
        $this->assertStringContainsString('created_at', $indexDefs);
    }
}
