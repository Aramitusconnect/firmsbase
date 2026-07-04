<?php

namespace Tests\Feature\Seats;

use App\Enums\SeatClass;
use App\Enums\SeatPoolStatus;
use App\Models\Organization;
use App\Services\SeatPoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeatPoolServiceTest extends TestCase
{
    use RefreshDatabase;

    private SeatPoolService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SeatPoolService();
    }

    public function test_create_pool(): void
    {
        $organization = Organization::factory()->create();

        $pool = $this->service->createPool($organization, SeatClass::Attorney, 10);

        $this->assertSame(10, $pool->total_seats);
        $this->assertSame(0, $pool->allocated_seats);
        $this->assertSame(SeatPoolStatus::Active, $pool->status);
        $this->assertSame(10, $pool->remainingSeats());
        $this->assertFalse($pool->isExhausted());
    }

    public function test_resize_changes_total_seats(): void
    {
        $organization = Organization::factory()->create();
        $pool = $this->service->createPool($organization, SeatClass::Staff, 5);

        $resized = $this->service->resize($pool, 20);

        $this->assertSame(20, $resized->total_seats);
    }

    public function test_suspend_and_close(): void
    {
        $organization = Organization::factory()->create();
        $pool = $this->service->createPool($organization, SeatClass::ReadOnly, 3);

        $this->assertSame(SeatPoolStatus::Suspended, $this->service->suspend($pool)->status);
        $this->assertSame(SeatPoolStatus::Closed, $this->service->close($pool->fresh())->status);
    }
}
