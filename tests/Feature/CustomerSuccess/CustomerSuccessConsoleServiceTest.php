<?php

namespace Tests\Feature\CustomerSuccess;

use App\Models\Firm;
use App\Models\Organization;
use App\Services\CustomerSuccessConsoleService;
use App\Services\CustomerSuccessHealthScoreService;
use App\Services\QueueHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerSuccessConsoleServiceTest extends TestCase
{
    use RefreshDatabase;

    private CustomerSuccessConsoleService $service;
    private CustomerSuccessHealthScoreService $healthScoreService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->healthScoreService = new CustomerSuccessHealthScoreService(new QueueHealthService());
        $this->service = new CustomerSuccessConsoleService();
    }

    public function test_snapshot_for_returns_null_when_no_score_has_been_computed(): void
    {
        $firm = Firm::factory()->create();

        $this->assertNull($this->service->snapshotFor($firm));
    }

    public function test_snapshot_for_returns_the_latest_computed_score_as_a_safe_summary(): void
    {
        $firm = Firm::factory()->create();
        $this->healthScoreService->compute($firm);

        $snapshot = $this->service->snapshotFor($firm);

        $this->assertNotNull($snapshot);
        $this->assertSame($firm->id, $snapshot->firmId);
        $this->assertIsInt($snapshot->score);
    }

    public function test_organization_rollup_aggregates_member_firms(): void
    {
        $organization = Organization::factory()->create();
        $firmA = Firm::factory()->create(['organization_id' => $organization->id]);
        $firmB = Firm::factory()->create(['organization_id' => $organization->id]);

        $this->healthScoreService->compute($firmA);
        $this->healthScoreService->compute($firmB);

        $rollup = $this->service->organizationRollup($organization);

        $this->assertSame(2, $rollup->memberFirmCount);
        $this->assertCount(2, $rollup->memberFirmSnapshots);
    }
}
