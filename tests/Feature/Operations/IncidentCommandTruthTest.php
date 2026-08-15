<?php

namespace Tests\Feature\Operations;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\PlatformRoleCode;
use App\Filament\Resources\PlatformIncidentResource;
use App\Models\IncidentEvent;
use App\Models\PlatformAdmin;
use App\Services\IncidentService;
use App\Services\PlatformRoleService;
use App\Services\StatusPageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Operations Control Plane — canonical incident command.
 *
 * Two themes: derived facts must come from the timeline (never from
 * invented columns), and gaps in the domain must be reported as gaps
 * rather than rendered as empty fields that read like "unassigned".
 */
class IncidentCommandTruthTest extends TestCase
{
    use RefreshDatabase;

    private function service(): IncidentService
    {
        return app(IncidentService::class);
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    private function openIncident(IncidentSeverity $severity = IncidentSeverity::Critical): IncidentEvent
    {
        return $this->service()->open(null, $severity, 'Elevated error rates on the API.');
    }

    // --- Derived facts come from real timeline rows ---

    public function test_detected_at_is_the_timestamp_of_the_opening_event(): void
    {
        $incident = $this->openIncident();

        $facts = $this->service()->derivedFacts($incident->correlation_id);

        $this->assertNotNull($facts['detected_at']);
        $this->assertSame(
            $incident->created_at->toDateTimeString(),
            $facts['detected_at']->toDateTimeString(),
        );
    }

    public function test_an_open_incident_reports_no_resolution_time_rather_than_zero(): void
    {
        $incident = $this->openIncident();

        $facts = $this->service()->derivedFacts($incident->correlation_id);

        $this->assertNull($facts['resolved_at']);
        $this->assertNull($facts['duration_seconds'], 'an unresolved incident has no duration, not a duration of 0');
    }

    public function test_resolution_time_is_measured_once_resolved(): void
    {
        $incident = $this->openIncident();
        $this->service()->resolve(null, $incident->correlation_id, 'Rolled back the bad deploy.');

        $facts = $this->service()->derivedFacts($incident->correlation_id);

        $this->assertNotNull($facts['resolved_at']);
        $this->assertNotNull($facts['duration_seconds']);
        $this->assertGreaterThanOrEqual(0, $facts['duration_seconds']);
        $this->assertSame(2, $facts['event_count']);
    }

    // --- The ownership gap is reported, not hidden ---

    public function test_incident_ownership_is_reported_as_unrecordable(): void
    {
        $evidence = $this->service()->ownershipEvidence();

        $this->assertFalse($evidence['available']);
        $this->assertNull($evidence['incident_commander']);
        $this->assertNull($evidence['technical_lead']);
        $this->assertNull($evidence['communications_lead']);
        $this->assertStringContainsString('records no owner', $evidence['reason']);
    }

    public function test_the_detail_page_states_that_ownership_is_not_recorded(): void
    {
        $incident = $this->openIncident();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')
            ->get(PlatformIncidentResource::getUrl('view', ['record' => $incident->getKey()]));

        $response->assertOk();
        $response->assertSee('Ownership — Not Recorded');
        $response->assertSee('Not Recorded');
    }

    // --- Canonical lifecycle ---

    public function test_the_current_state_is_always_the_latest_event(): void
    {
        $incident = $this->openIncident(IncidentSeverity::Low);

        $this->service()->updateSeverity(null, $incident->correlation_id, IncidentSeverity::Critical);
        $this->service()->updateStatus(null, $incident->correlation_id, IncidentStatus::Monitoring);

        $current = $this->service()->currentState($incident->correlation_id);

        $this->assertSame(IncidentSeverity::Critical, $current->severity);
        $this->assertSame(IncidentStatus::Monitoring, $current->status);
    }

    public function test_the_canonical_severity_vocabulary_is_reused_not_reinvented(): void
    {
        // No SEV-1/SEV-2 vocabulary is introduced anywhere: this
        // platform's canonical severity scale is Critical/High/Medium/
        // Low and the console must speak it.
        $this->assertSame(
            ['critical', 'high', 'medium', 'low'],
            array_map(fn (IncidentSeverity $s): string => $s->value, IncidentSeverity::cases()),
        );
        $this->assertSame(
            ['investigating', 'identified', 'monitoring', 'resolved'],
            array_map(fn (IncidentStatus $s): string => $s->value, IncidentStatus::cases()),
        );
    }

    // --- Idempotency / concurrency ---

    public function test_resolving_twice_leaves_one_consistent_terminal_state(): void
    {
        $incident = $this->openIncident();

        $this->service()->resolve(null, $incident->correlation_id, 'First resolution.');
        $this->service()->resolve(null, $incident->correlation_id, 'Second resolution.');

        $current = $this->service()->currentState($incident->correlation_id);

        // The append-only design means a second resolve records a
        // second event rather than conflicting — the important
        // property is that the terminal state stays Resolved and does
        // not flip back or become ambiguous.
        $this->assertSame(IncidentStatus::Resolved, $current->status);
        $this->assertTrue($current->isResolved());
    }

    public function test_a_status_change_after_resolution_is_recorded_without_losing_history(): void
    {
        $incident = $this->openIncident();
        $this->service()->resolve(null, $incident->correlation_id, 'Resolved.');
        $this->service()->updateStatus(null, $incident->correlation_id, IncidentStatus::Monitoring);

        $timeline = $this->service()->timeline($incident->correlation_id);

        $this->assertSame(3, $timeline->count(), 'no history is overwritten');
        $this->assertSame(IncidentStatus::Monitoring, $timeline->last()->status);
        $this->assertSame(
            'Resolved.',
            $timeline->last()->resolution,
            'the recorded resolution text carries forward rather than being silently dropped',
        );
    }

    public function test_concurrent_severity_changes_both_appear_in_the_timeline(): void
    {
        $incident = $this->openIncident(IncidentSeverity::Low);

        $this->service()->updateSeverity(null, $incident->correlation_id, IncidentSeverity::High);
        $this->service()->updateSeverity(null, $incident->correlation_id, IncidentSeverity::Critical);

        $timeline = $this->service()->timeline($incident->correlation_id);

        $this->assertSame(3, $timeline->count());
        $this->assertSame(IncidentSeverity::Critical, $timeline->last()->severity, 'last writer wins, unambiguously');
    }

    // --- Cross-links are real relationships ---

    public function test_linked_status_updates_come_from_a_recorded_relation_not_text_matching(): void
    {
        $incident = $this->openIncident();

        app(StatusPageService::class)->publish(
            'investigating',
            'API',
            'We are investigating elevated error rates.',
            now(),
            $incident->correlation_id,
        );

        $linked = app(StatusPageService::class)->forIncident($incident->correlation_id);

        $this->assertCount(1, $linked);
        $this->assertSame($incident->correlation_id, $linked->first()->incident_correlation_id);
    }

    public function test_an_unlinked_status_update_is_not_attributed_to_an_incident(): void
    {
        $incident = $this->openIncident();

        app(StatusPageService::class)->publish('maintenance_scheduled', 'API', 'Planned maintenance.', now());

        $this->assertCount(
            0,
            app(StatusPageService::class)->forIncident($incident->correlation_id),
            'only an explicitly recorded correlation counts as a link',
        );
    }

    /**
     * Exercises the resolved-incident render path specifically. The
     * open-incident path short-circuits before formatting a duration,
     * so only a resolved incident proves the duration rendering
     * actually works.
     */
    public function test_a_resolved_incident_detail_page_renders_its_measured_duration(): void
    {
        $incident = $this->openIncident();
        $this->service()->resolve(null, $incident->correlation_id, 'Rolled back the bad deploy.');

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')
            ->get(PlatformIncidentResource::getUrl('view', ['record' => $incident->getKey()]));

        $response->assertOk();
        $response->assertSee('Time to resolution');
        $response->assertDontSee('Still open');
    }

    public function test_the_detail_page_surfaces_the_customer_communication_state(): void
    {
        $incident = $this->openIncident();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')
            ->get(PlatformIncidentResource::getUrl('view', ['record' => $incident->getKey()]));

        $response->assertOk();
        $response->assertSee('Customer Communication');
        $response->assertSee('No status update has been linked to this incident.');
    }
}
