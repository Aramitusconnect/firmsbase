<?php

namespace Tests\Feature;

use App\Models\PlatformLead;
use App\Models\PlatformSalesTask;
use App\Services\PlatformSalesLeadService;
use App\Services\PlatformSalesTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Confirms the approved Phase 7 naming decisions: platform_leads and
 * platform_sales_tasks are new, distinct tables, and platform sales
 * operations never write to Phase 2's firm_leads or Phase 4's tasks
 * table.
 */
class Phase7NoDuplicateTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_leads_table_exists_and_is_distinct_from_firm_leads(): void
    {
        $this->assertTrue(Schema::hasTable('platform_leads'));
        $this->assertTrue(Schema::hasTable('firm_leads'));

        $platformLeadColumns = Schema::getColumnListing('platform_leads');
        $this->assertContains('company_name', $platformLeadColumns);
        $this->assertNotContains('converted_client_id', $platformLeadColumns);
    }

    public function test_platform_sales_tasks_table_exists_and_is_distinct_from_tasks(): void
    {
        $this->assertTrue(Schema::hasTable('platform_sales_tasks'));
        $this->assertTrue(Schema::hasTable('tasks'));

        $platformSalesTaskColumns = Schema::getColumnListing('platform_sales_tasks');
        $this->assertNotContains('matter_id', $platformSalesTaskColumns);
        $this->assertNotContains('client_id', $platformSalesTaskColumns);
    }

    public function test_creating_a_platform_lead_does_not_write_to_firm_leads(): void
    {
        (new PlatformSalesLeadService())->create(['company_name' => 'Acme Legal', 'contact_name' => 'Jane Doe']);

        $this->assertDatabaseCount('platform_leads', 1);
        $this->assertDatabaseCount('firm_leads', 0);
    }

    public function test_creating_a_platform_sales_task_does_not_write_to_tasks(): void
    {
        $lead = PlatformLead::factory()->create();
        (new PlatformSalesTaskService())->create($lead, 'Follow up');

        $this->assertDatabaseCount('platform_sales_tasks', 1);
        $this->assertDatabaseCount('tasks', 0);
    }
}
