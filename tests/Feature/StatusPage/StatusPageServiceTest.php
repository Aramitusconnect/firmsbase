<?php

namespace Tests\Feature\StatusPage;

use App\Enums\StatusPageEventStatus;
use App\Services\IncidentService;
use App\Services\StatusPageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatusPageServiceTest extends TestCase
{
    use RefreshDatabase;

    private StatusPageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StatusPageService();
    }

    public function test_publish_creates_a_published_status_page_event(): void
    {
        $event = $this->service->publish('investigating', 'client_portal', 'We are investigating an issue.', now());

        $this->assertSame(StatusPageEventStatus::Published, $event->status);
        $this->assertNotEmpty($event->uuid);
    }

    public function test_update_appends_a_new_row_under_the_same_correlation_id(): void
    {
        $published = $this->service->publish('investigating', 'email_delivery', 'Investigating email delays.', now());

        $this->service->update($published->correlation_id, 'identified', 'Root cause identified.');

        $timeline = $this->service->timeline($published->correlation_id);

        $this->assertCount(2, $timeline);
    }

    public function test_resolve_publicly_sets_resolved_at_and_appends_a_resolved_event(): void
    {
        $published = $this->service->publish('investigating', 'storage', 'Investigating storage issues.', now());

        $resolved = $this->service->resolvePublicly($published->correlation_id, 'Issue resolved.');

        $this->assertNotNull($resolved->resolved_at);
        $this->assertSame('resolved', $resolved->event_type);
    }

    public function test_a_status_page_event_can_link_to_an_incidents_correlation_id(): void
    {
        $incidents = new IncidentService();
        $incident = $incidents->open(null, \App\Enums\IncidentSeverity::High, 'Outage');

        $published = $this->service->publish('investigating', 'client_portal', 'We are aware of an outage.', now(), $incident->correlation_id);

        $linked = $this->service->forIncident($incident->correlation_id);

        $this->assertCount(1, $linked);
        $this->assertTrue($linked->first()->is($published));
    }

    public function test_unpublish_sets_status_unpublished(): void
    {
        $published = $this->service->publish('maintenance_scheduled', 'documents', 'Planned maintenance.', now());

        $unpublished = $this->service->unpublish($published->correlation_id);

        $this->assertSame(StatusPageEventStatus::Unpublished, $unpublished->status);
    }
}
