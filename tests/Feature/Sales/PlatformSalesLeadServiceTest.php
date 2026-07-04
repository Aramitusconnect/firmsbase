<?php

namespace Tests\Feature\Sales;

use App\Enums\PlatformLeadStatus;
use App\Models\PlatformAdmin;
use App\Services\PlatformSalesLeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformSalesLeadServiceTest extends TestCase
{
    use RefreshDatabase;

    private PlatformSalesLeadService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PlatformSalesLeadService();
    }

    public function test_create_writes_a_new_platform_lead_with_new_status(): void
    {
        $lead = $this->service->create([
            'company_name' => 'Acme Legal',
            'contact_name' => 'Jane Doe',
            'contact_email' => 'jane@acmelegal.test',
        ]);

        $this->assertSame(PlatformLeadStatus::New, $lead->status);
        $this->assertDatabaseHas('platform_leads', ['company_name' => 'Acme Legal']);
    }

    public function test_assign_to_sets_the_assigned_platform_admin(): void
    {
        $lead = $this->service->create(['company_name' => 'Acme Legal', 'contact_name' => 'Jane Doe']);
        $admin = PlatformAdmin::factory()->create();

        $updated = $this->service->assignTo($lead, $admin);

        $this->assertSame($admin->id, $updated->assigned_to);
    }

    public function test_disqualify_sets_disqualified_status(): void
    {
        $lead = $this->service->create(['company_name' => 'Acme Legal', 'contact_name' => 'Jane Doe']);

        $updated = $this->service->disqualify($lead, 'Too small for our pricing tier');

        $this->assertSame(PlatformLeadStatus::Disqualified, $updated->status);
    }
}
