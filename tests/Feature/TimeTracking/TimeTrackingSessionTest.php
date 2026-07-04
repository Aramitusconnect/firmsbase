<?php

namespace Tests\Feature\TimeTracking;

use App\Enums\TimeTrackingSessionStatus;
use App\Models\TimeTrackingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimeTrackingSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_via_factory(): void
    {
        $session = TimeTrackingSession::factory()->create();

        $this->assertDatabaseHas('time_tracking_sessions', ['id' => $session->id]);
        $this->assertSame(TimeTrackingSessionStatus::Active, $session->status);
        $this->assertTrue($session->isActive());
    }

    public function test_no_uuid_column_exists(): void
    {
        $session = TimeTrackingSession::factory()->create();

        $this->assertArrayNotHasKey('uuid', $session->getAttributes());
    }
}
