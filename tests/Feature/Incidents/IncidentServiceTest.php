<?php

namespace Tests\Feature\Incidents;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Models\Firm;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentServiceTest extends TestCase
{
    use RefreshDatabase;

    private IncidentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new IncidentService();
    }

    public function test_open_creates_an_investigating_incident_with_no_separate_incidents_table(): void
    {
        $opened = $this->service->open(null, IncidentSeverity::High, 'Email delivery degraded');

        $this->assertSame(IncidentStatus::Investigating, $opened->status);
        $this->assertSame(IncidentSeverity::High, $opened->severity);
        $this->assertSame('opened', $opened->event_type);
    }

    public function test_the_full_timeline_accumulates_every_update_under_one_correlation_id(): void
    {
        $opened = $this->service->open(null, IncidentSeverity::Medium, 'Storage latency spike');

        $this->service->updateStatus($opened->correlation_id, IncidentStatus::Identified);
        $this->service->recordRootCause($opened->correlation_id, 'Disk contention on primary node');
        $this->service->resolve($opened->correlation_id, 'Scaled storage tier');

        $timeline = $this->service->timeline($opened->correlation_id);

        $this->assertCount(4, $timeline);
        $this->assertSame(['opened', 'status_changed', 'root_cause_added', 'resolved'], $timeline->pluck('event_type')->all());
    }

    public function test_current_state_is_always_the_latest_row_for_the_correlation_id(): void
    {
        $opened = $this->service->open(null, IncidentSeverity::Low, 'Minor blip');
        $this->service->updateSeverity($opened->correlation_id, IncidentSeverity::Critical);

        $current = $this->service->currentState($opened->correlation_id);

        $this->assertSame(IncidentSeverity::Critical, $current->severity);
    }

    public function test_customer_impact_and_notification_needed_flags_carry_forward_until_changed(): void
    {
        $opened = $this->service->open(null, IncidentSeverity::High, 'API errors', customerImpact: true, notificationNeeded: true);

        $this->service->recordRootCause($opened->correlation_id, 'Bad deploy');
        $current = $this->service->currentState($opened->correlation_id);

        $this->assertTrue($current->customer_impact);
        $this->assertTrue($current->notification_needed);
    }

    public function test_resolve_sets_status_resolved_and_records_the_resolution_text(): void
    {
        $opened = $this->service->open(null, IncidentSeverity::Medium, 'Slow queries');

        $resolved = $this->service->resolve($opened->correlation_id, 'Added missing index');

        $this->assertSame(IncidentStatus::Resolved, $resolved->status);
        $this->assertSame('Added missing index', $resolved->resolution);
        $this->assertTrue($this->service->currentState($opened->correlation_id)->isResolved());
    }

    public function test_an_incident_can_be_scoped_to_a_specific_firm(): void
    {
        $firm = Firm::factory()->create();

        $opened = $this->service->open($firm, IncidentSeverity::High, 'Tenant-specific anomaly', customerImpact: true);

        $this->assertSame($firm->id, $opened->firm_id);
    }
}
