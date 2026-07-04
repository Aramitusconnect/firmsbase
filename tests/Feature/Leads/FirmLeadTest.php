<?php

namespace Tests\Feature\Leads;

use App\Enums\FirmLeadStatus;
use App\Models\FirmLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FirmLeadTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_via_factory(): void
    {
        $lead = FirmLead::factory()->create();

        $this->assertDatabaseHas('firm_leads', ['id' => $lead->id]);
        $this->assertSame(FirmLeadStatus::New, $lead->status);
    }

    public function test_is_converted_false_before_conversion(): void
    {
        $lead = FirmLead::factory()->create();

        $this->assertFalse($lead->isConverted());
    }

    public function test_is_converted_true_only_when_status_and_client_both_set(): void
    {
        $lead = FirmLead::factory()->status(FirmLeadStatus::Converted)->create();

        $this->assertFalse($lead->isConverted(), 'status alone without converted_client_id must not count as converted');
    }
}
