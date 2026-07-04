<?php

namespace Tests\Feature\Announcements;

use App\Enums\AnnouncementSeverity;
use App\Models\Firm;
use App\Models\Organization;
use App\Models\Plan;
use App\Services\AnnouncementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnouncementServiceTest extends TestCase
{
    use RefreshDatabase;

    private AnnouncementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AnnouncementService();
    }

    public function test_broadcast_announcement_targets_everyone(): void
    {
        $announcement = $this->service->create([
            'type' => 'general',
            'severity' => 'info',
            'title' => 'Welcome',
            'body' => 'Body',
        ]);
        $this->service->publish($announcement);

        $this->assertTrue($announcement->isBroadcast());

        $results = $this->service->targetedFor(null, null, null, null);

        $this->assertTrue($results->contains('id', $announcement->id));
    }

    public function test_announcement_can_target_by_organization_firm_plan_and_module(): void
    {
        $organization = Organization::factory()->create();
        $firm = Firm::factory()->create();
        $plan = Plan::factory()->create();

        $orgTargeted = $this->service->create(['organization_id' => $organization->id, 'type' => 'general', 'severity' => 'info', 'title' => 'Org', 'body' => 'x']);
        $firmTargeted = $this->service->create(['firm_id' => $firm->id, 'type' => 'general', 'severity' => 'info', 'title' => 'Firm', 'body' => 'x']);
        $planTargeted = $this->service->create(['plan_id' => $plan->id, 'type' => 'general', 'severity' => 'info', 'title' => 'Plan', 'body' => 'x']);
        $moduleTargeted = $this->service->create(['module_code' => 'ai', 'type' => 'module_update', 'severity' => 'info', 'title' => 'Module', 'body' => 'x']);

        foreach ([$orgTargeted, $firmTargeted, $planTargeted, $moduleTargeted] as $a) {
            $this->service->publish($a);
        }

        $resultsForOrg = $this->service->targetedFor($organization->id, null, null, null);
        $this->assertTrue($resultsForOrg->contains('id', $orgTargeted->id));
        $this->assertFalse($resultsForOrg->contains('id', $firmTargeted->id));

        $resultsForFirm = $this->service->targetedFor(null, $firm->id, null, null);
        $this->assertTrue($resultsForFirm->contains('id', $firmTargeted->id));

        $resultsForPlan = $this->service->targetedFor(null, null, $plan->id, null);
        $this->assertTrue($resultsForPlan->contains('id', $planTargeted->id));

        $resultsForModule = $this->service->targetedFor(null, null, null, 'ai');
        $this->assertTrue($resultsForModule->contains('id', $moduleTargeted->id));
    }

    public function test_min_severity_filters_out_announcements_above_viewer_threshold(): void
    {
        $critical = $this->service->create(['type' => 'security', 'severity' => 'critical', 'min_severity' => 'critical', 'title' => 'Critical', 'body' => 'x']);
        $this->service->publish($critical);

        $lowThreshold = $this->service->targetedFor(null, null, null, null, AnnouncementSeverity::Info);
        $this->assertFalse($lowThreshold->contains('id', $critical->id));

        $highThreshold = $this->service->targetedFor(null, null, null, null, AnnouncementSeverity::Critical);
        $this->assertTrue($highThreshold->contains('id', $critical->id));
    }

    public function test_announcement_carries_both_severity_and_min_severity_columns(): void
    {
        $announcement = $this->service->create([
            'type' => 'general',
            'severity' => 'warning',
            'min_severity' => 'info',
            'title' => 'Both fields',
            'body' => 'x',
        ]);

        $this->assertSame(AnnouncementSeverity::Warning, $announcement->severity);
        $this->assertSame(AnnouncementSeverity::Info, $announcement->min_severity);
    }
}
