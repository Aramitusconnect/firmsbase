<?php

namespace Tests\Feature\Entitlements;

use App\Models\FirmEntitlementEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FirmEntitlementEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_via_factory(): void
    {
        $event = FirmEntitlementEvent::factory()->create();

        $this->assertDatabaseHas('firm_entitlement_events', ['id' => $event->id]);
    }

    public function test_no_updated_at_column_exists(): void
    {
        $event = FirmEntitlementEvent::factory()->create();

        $this->assertArrayNotHasKey('updated_at', $event->getAttributes());
    }

    public function test_no_uuid_column_exists(): void
    {
        $event = FirmEntitlementEvent::factory()->create();

        $this->assertArrayNotHasKey('uuid', $event->getAttributes());
    }
}
