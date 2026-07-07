<?php

namespace Tests\Feature\Governance\EntityFieldCatalog;

use App\Services\EntityFieldCatalogMappingService;
use Tests\TestCase;

class EntityFieldCatalogBoundaryTest extends TestCase
{
    private EntityFieldCatalogMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EntityFieldCatalogMappingService();
    }

    public function test_tenant_owned_catalog_tables_have_firm_id_or_documented_transitive_scoping(): void
    {
        $coverage = $this->service->tenantBoundaryCoverage();

        $this->assertCount(32, $coverage);

        foreach ($coverage as $item) {
            $this->assertNotEmpty($item->notes, "{$item->item_key} tenant boundary notes must not be empty.");
        }
    }

    public function test_transitively_scoped_tables_are_documented_not_missing(): void
    {
        $coverage = $this->service->tenantBoundaryCoverage();
        $byKey = [];
        foreach ($coverage as $item) {
            $byKey[$item->item_key] = $item;
        }

        foreach (['matter_parties.tenant_boundary', 'task_dependencies.tenant_boundary', 'payment_plan_installments.tenant_boundary'] as $key) {
            $this->assertArrayHasKey($key, $byKey);
            $this->assertStringContainsString('transitively', $byKey[$key]->notes);
        }
    }

    public function test_organizations_billing_accounts_firms_hierarchy_is_represented(): void
    {
        $firmsFields = $this->service->table('firms');

        $this->assertArrayHasKey('firms.organization_id', $firmsFields);
        $this->assertArrayHasKey('firms.billing_account_id', $firmsFields);

        $billingAccountsFields = $this->service->table('billing_accounts');
        $this->assertArrayHasKey('billing_accounts.organization_id', $billingAccountsFields);
    }

    public function test_tasks_and_deadlines_uuid_absence_is_documented_as_section_26_supporting_evidence_not_a_new_gap(): void
    {
        $coverage = $this->service->publicIdentifierCoverage();

        $this->assertNotEmpty($coverage);

        $combinedNotes = implode(' ', array_map(fn ($item) => $item->notes, $coverage));

        $this->assertStringContainsString('Task', $combinedNotes);
        $this->assertStringContainsString('Deadline', $combinedNotes);

        $gapKeys = array_map(fn ($g) => $g->item_key, $this->service->gaps());
        $this->assertEmpty(array_filter($gapKeys, fn ($k) => str_contains($k, 'uuid')));
    }

    public function test_public_identifier_coverage_references_section_26_data_model_contract_service(): void
    {
        $coverage = $this->service->publicIdentifierCoverage();

        $this->assertNotEmpty($coverage);
        foreach ($coverage as $item) {
            $this->assertStringContainsString('DataModelContractMappingService', $item->notes);
        }
    }
}
