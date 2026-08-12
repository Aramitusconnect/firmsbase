<?php

namespace Tests\Feature\Leads;

use App\Enums\DomainEventType;
use App\Enums\FirmLeadStatus;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Models\FirmLead;
use App\Models\User;
use App\Services\LeadConversionService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadConversionServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeadConversionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LeadConversionService(new TimelineEventRecorder);
    }

    public function test_convert_creates_client_and_marks_lead_converted(): void
    {
        $firm = Firm::factory()->create();
        $lead = FirmLead::factory()->forFirm($firm)->create();
        $actor = User::factory()->create();

        $client = $this->service->convert($lead, ['display_name' => 'Jane Doe', 'email' => 'jane@example.com'], $actor);

        $this->assertSame($firm->id, $client->firm_id);
        $this->assertSame('Jane Doe', $client->display_name);
        $this->assertSame($actor->id, $client->created_by);

        // firm_leads has permanent FORCE ROW LEVEL SECURITY (Section
        // 39A-3J) — LeadConversionService::convert() clears its own
        // tenant context in a finally block before returning, so this
        // post-call read needs explicit tenant context re-established.
        $fresh = $this->runWithFirmContext($firm, fn () => $lead->fresh());
        $this->assertSame(FirmLeadStatus::Converted, $fresh->status);
        $this->assertSame($client->id, $fresh->converted_client_id);
        $this->assertNotNull($fresh->converted_at);
        $this->assertTrue($fresh->isConverted());
    }

    public function test_convert_throws_if_lead_already_converted(): void
    {
        $lead = FirmLead::factory()->create();
        $this->service->convert($lead, ['display_name' => 'First Client']);

        $this->expectException(\RuntimeException::class);

        // firm_leads has permanent FORCE ROW LEVEL SECURITY (Section
        // 39A-3J) — the first convert() call above already cleared
        // tenant context in its finally block, so re-reading $lead
        // needs explicit tenant context re-established.
        $this->service->convert($this->runWithFirmContext($lead->firm_id, fn () => $lead->fresh()), ['display_name' => 'Second Client']);
    }

    public function test_convert_marks_consultation_converted_when_provided(): void
    {
        $lead = FirmLead::factory()->create();
        $consultation = Consultation::factory()->forLead($lead)->held()->create();

        $this->service->convert($lead, ['display_name' => 'Jane Doe'], null, $consultation);

        // consultations has permanent FORCE ROW LEVEL SECURITY
        // (Section 39A-3J) — convert() clears its own tenant context
        // in a finally block before returning, so this post-call read
        // needs explicit tenant context re-established.
        $this->assertTrue($this->runWithFirmContext($lead->firm_id, fn () => $consultation->fresh())->converted);
    }

    public function test_convert_writes_timeline_events(): void
    {
        $lead = FirmLead::factory()->create();

        $client = $this->service->convert($lead, ['display_name' => 'Jane Doe']);

        // timeline_events has permanent FORCE ROW LEVEL SECURITY (Section
        // 39A-3L, Checkpoint 33) — convert()'s own tenant-context wrap
        // around its timeline->record() calls clears before returning,
        // so these read-time assertions need their own context wrap to
        // see the rows just written, matching this file's own sibling
        // tests' already-established pattern. Pre-existing gap in this
        // one test surfaced by the first full unfiltered suite run since
        // Checkpoint 33 landed (this test's service wasn't among the six
        // whose own test files were re-run at that checkpoint, since
        // LeadConversionService's own call sites were already correct
        // and untouched).
        $this->runWithFirmContext($lead->firm_id, function () use ($lead, $client) {
            $this->assertDatabaseHas('timeline_events', [
                'firm_id' => $lead->firm_id,
                'subject_type' => FirmLead::class,
                'subject_id' => $lead->id,
                'event_type' => 'lead_converted',
            ]);

            $this->assertDatabaseHas('timeline_events', [
                'firm_id' => $lead->firm_id,
                'subject_type' => Client::class,
                'subject_id' => $client->id,
                'event_type' => 'client_created',
            ]);
        });
    }

    public function test_convert_emits_a_client_created_domain_event(): void
    {
        $firm = Firm::factory()->create();
        $lead = FirmLead::factory()->forFirm($firm)->create();

        $client = $this->service->convert($lead, ['display_name' => 'Jane Doe']);

        // domain_events has permanent FORCE ROW LEVEL SECURITY — convert()
        // clears its own tenant context in a finally block before
        // returning, so this post-call read needs explicit tenant
        // context re-established (same pattern as this file's other
        // post-call assertions).
        $event = $this->runWithFirmContext($firm, fn () => DomainEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', DomainEventType::ClientCreated)
            ->sole());

        $this->assertSame($client->getMorphClass(), $event->subject_type);
        $this->assertSame($client->id, $event->subject_id);
        $this->assertSame($client->id, $event->payload_json['client']['id']);
    }
}
