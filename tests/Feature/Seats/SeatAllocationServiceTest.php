<?php

namespace Tests\Feature\Seats;

use App\Enums\SeatAllocationStatus;
use App\Enums\SeatClass;
use App\Models\Firm;
use App\Models\Organization;
use App\Services\SeatAllocationService;
use App\Services\SeatPoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeatAllocationServiceTest extends TestCase
{
    use RefreshDatabase;

    private SeatAllocationService $service;
    private SeatPoolService $poolService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SeatAllocationService();
        $this->poolService = new SeatPoolService();
    }

    public function test_allocate_direct_creates_a_non_pooled_allocation(): void
    {
        $firm = Firm::factory()->create();

        $allocation = $this->service->allocateDirect($firm, SeatClass::Attorney, 5);

        $this->assertFalse($allocation->isPooled());
        $this->assertSame(5, $allocation->seats_allocated);
        $this->assertSame(SeatAllocationStatus::Active, $allocation->status);
    }

    public function test_allocate_from_pool_increments_the_pool_counter(): void
    {
        $organization = Organization::factory()->create();
        $firm = Firm::factory()->create(['organization_id' => $organization->id]);
        $pool = $this->poolService->createPool($organization, SeatClass::Staff, 10);

        $allocation = $this->service->allocateFromPool($firm, $pool, 4);

        $this->assertTrue($allocation->isPooled());
        $this->assertSame(4, $pool->fresh()->allocated_seats);
    }

    public function test_allocate_from_pool_blocks_when_seats_exceed_remaining(): void
    {
        $organization = Organization::factory()->create();
        $firm = Firm::factory()->create(['organization_id' => $organization->id]);
        $pool = $this->poolService->createPool($organization, SeatClass::Attorney, 3);

        $this->expectException(\RuntimeException::class);

        $this->service->allocateFromPool($firm, $pool, 4);
    }

    public function test_allocate_from_pool_never_silently_exceeds_the_pool_across_multiple_firms(): void
    {
        $organization = Organization::factory()->create();
        $firmA = Firm::factory()->create(['organization_id' => $organization->id]);
        $firmB = Firm::factory()->create(['organization_id' => $organization->id]);
        $pool = $this->poolService->createPool($organization, SeatClass::Attorney, 5);

        $this->service->allocateFromPool($firmA, $pool, 3);

        $this->expectException(\RuntimeException::class);
        $this->service->allocateFromPool($firmB, $pool->fresh(), 3);
    }

    public function test_revoke_a_pooled_allocation_decrements_the_pool(): void
    {
        $organization = Organization::factory()->create();
        $firm = Firm::factory()->create(['organization_id' => $organization->id]);
        $pool = $this->poolService->createPool($organization, SeatClass::Staff, 10);
        $allocation = $this->service->allocateFromPool($firm, $pool, 4);

        $this->service->revoke($allocation);

        $this->assertSame(0, $pool->fresh()->allocated_seats);
        $this->assertSame(SeatAllocationStatus::Revoked, $allocation->fresh()->status);
    }

    public function test_revoke_a_direct_allocation_does_not_touch_any_pool(): void
    {
        $firm = Firm::factory()->create();
        $allocation = $this->service->allocateDirect($firm, SeatClass::ReadOnly, 2);

        $revoked = $this->service->revoke($allocation);

        $this->assertSame(SeatAllocationStatus::Revoked, $revoked->status);
    }
}
