<?php

namespace Tests\Feature\Sales;

use App\Enums\SalesAssignmentStatus;
use App\Models\PlatformAdmin;
use App\Models\PlatformLead;
use App\Services\SalesRepAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesRepAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private SalesRepAssignmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SalesRepAssignmentService();
    }

    public function test_assign_creates_an_active_assignment_for_a_platform_lead(): void
    {
        $lead = PlatformLead::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        $assignment = $this->service->assign($lead, $admin);

        $this->assertSame(SalesAssignmentStatus::Active, $assignment->status);
        $this->assertSame(PlatformLead::class, $assignment->assignable_type);
        $this->assertSame($lead->id, $assignment->assignable_id);
    }

    public function test_reassign_closes_old_assignment_and_creates_a_new_one(): void
    {
        $lead = PlatformLead::factory()->create();
        $adminA = PlatformAdmin::factory()->create();
        $adminB = PlatformAdmin::factory()->create();

        $original = $this->service->assign($lead, $adminA);
        $reassigned = $this->service->reassign($original, $adminB);

        $this->assertSame(SalesAssignmentStatus::Reassigned, $original->fresh()->status);
        $this->assertSame(SalesAssignmentStatus::Active, $reassigned->status);
        $this->assertSame($adminB->id, $reassigned->platform_admin_id);
    }

    public function test_close_marks_assignment_closed(): void
    {
        $lead = PlatformLead::factory()->create();
        $admin = PlatformAdmin::factory()->create();
        $assignment = $this->service->assign($lead, $admin);

        $closed = $this->service->close($assignment);

        $this->assertSame(SalesAssignmentStatus::Closed, $closed->status);
    }
}
