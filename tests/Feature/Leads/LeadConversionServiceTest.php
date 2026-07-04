<?php

namespace Tests\Feature\Leads;

use App\Enums\FirmLeadStatus;
use App\Models\Consultation;
use App\Models\Firm;
use App\Models\FirmLead;
use App\Models\User;
use App\Services\LeadConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadConversionServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeadConversionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LeadConversionService(new \App\Services\TimelineEventRecorder());
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

        $fresh = $lead->fresh();
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

        $this->service->convert($lead->fresh(), ['display_name' => 'Second Client']);
    }

    public function test_convert_marks_consultation_converted_when_provided(): void
    {
        $lead = FirmLead::factory()->create();
        $consultation = Consultation::factory()->forLead($lead)->held()->create();

        $this->service->convert($lead, ['display_name' => 'Jane Doe'], null, $consultation);

        $this->assertTrue($consultation->fresh()->converted);
    }

    public function test_convert_writes_timeline_events(): void
    {
        $lead = FirmLead::factory()->create();

        $client = $this->service->convert($lead, ['display_name' => 'Jane Doe']);

        $this->assertDatabaseHas('timeline_events', [
            'firm_id' => $lead->firm_id,
            'subject_type' => FirmLead::class,
            'subject_id' => $lead->id,
            'event_type' => 'lead_converted',
        ]);

        $this->assertDatabaseHas('timeline_events', [
            'firm_id' => $lead->firm_id,
            'subject_type' => \App\Models\Client::class,
            'subject_id' => $client->id,
            'event_type' => 'client_created',
        ]);
    }
}
