<?php

namespace Tests\Feature\Entitlements;

use App\Enums\EntitlementSource;
use App\Models\Firm;
use App\Models\ModuleCatalog;
use App\Models\Organization;
use App\Models\OrgLicense;
use App\Models\Plan;
use App\Models\PlanModule;
use App\Services\EntitlementPlanSyncService;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Confirms EntitlementPlanSyncService writes ONLY through the EXISTING
 * EntitlementService::setForSource() — no new columns, no new table,
 * no change to precedence. Also re-confirms (regression safety) that
 * EntitlementService::resolve()'s precedence order — admin_override >
 * firm_override > org_inherited > plan — still holds once Plan/
 * OrgInherited rows are written this way, exactly matching the
 * EXISTING (untouched) EntitlementServiceTest's own assumptions.
 */
class EntitlementPlanSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private EntitlementPlanSyncService $service;
    private EntitlementService $entitlementService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entitlementService = new EntitlementService();
        $this->service = new EntitlementPlanSyncService($this->entitlementService);
    }

    public function test_sync_plan_entitlements_writes_one_row_per_plan_module(): void
    {
        $firm = Firm::factory()->create();
        $plan = Plan::factory()->create();
        $moduleA = $this->module('invoices');
        $moduleB = $this->module('payments');
        PlanModule::factory()->forPlan($plan)->forModuleCode($moduleA->module_code)->create(['enabled' => true]);
        PlanModule::factory()->forPlan($plan)->forModuleCode($moduleB->module_code)->create(['enabled' => false]);

        $written = $this->service->syncPlanEntitlements($firm, $plan);

        $this->assertCount(2, $written);
        $this->assertTrue($this->entitlementService->isEnabled($firm->id, 'invoices'));
        $this->assertFalse($this->entitlementService->isEnabled($firm->id, 'payments'));

        // Section 39A-3L, Checkpoint 4 — firm_entitlements now has FORCE
        // ROW LEVEL SECURITY active. assertDatabaseHas() queries with no
        // tenant context of its own, so it would now see zero rows.
        // syncPlanEntitlements() -> EntitlementService::setForSource()
        // already cleared context by the time control returns here, so
        // this is a genuinely fresh, explicitly context-wrapped read.
        $this->runWithFirmContext($firm, function () use ($firm) {
            $this->assertDatabaseHas('firm_entitlements', [
                'firm_id' => $firm->id,
                'module_code' => 'invoices',
                'source' => EntitlementSource::Plan->value,
            ]);
        });
    }

    public function test_sync_org_inherited_entitlements_writes_org_inherited_source(): void
    {
        $organization = Organization::factory()->create();
        $firm = Firm::factory()->create(['organization_id' => $organization->id]);
        $plan = Plan::factory()->create();
        $module = $this->module('client_portal');
        PlanModule::factory()->forPlan($plan)->forModuleCode($module->module_code)->create(['enabled' => true]);
        $orgLicense = OrgLicense::factory()->forOrganization($organization)->create(['plan_id' => $plan->id]);

        $this->service->syncOrgInheritedEntitlements($firm, $orgLicense);

        // Section 39A-3L, Checkpoint 4 — same reasoning as
        // test_sync_plan_entitlements_writes_one_row_per_plan_module
        // above: a genuinely fresh, explicitly context-wrapped read.
        $this->runWithFirmContext($firm, function () use ($firm) {
            $this->assertDatabaseHas('firm_entitlements', [
                'firm_id' => $firm->id,
                'module_code' => 'client_portal',
                'source' => EntitlementSource::OrgInherited->value,
                'enabled' => true,
            ]);
        });
    }

    public function test_firm_override_still_wins_over_a_synced_plan_entitlement(): void
    {
        $firm = Firm::factory()->create();
        $plan = Plan::factory()->create();
        $module = $this->module('forms');
        PlanModule::factory()->forPlan($plan)->forModuleCode($module->module_code)->create(['enabled' => false]);

        $this->service->syncPlanEntitlements($firm, $plan);
        $this->entitlementService->setForSource($firm, 'forms', EntitlementSource::FirmOverride, true);

        $resolution = $this->entitlementService->resolve($firm->id, 'forms');

        $this->assertTrue($resolution->enabled);
        $this->assertSame(EntitlementSource::FirmOverride, $resolution->source);
    }

    public function test_org_inherited_wins_over_plan_when_both_synced(): void
    {
        $organization = Organization::factory()->create();
        $firm = Firm::factory()->create(['organization_id' => $organization->id]);
        $planA = Plan::factory()->create();
        $planB = Plan::factory()->create();
        $module = $this->module('email');
        PlanModule::factory()->forPlan($planA)->forModuleCode($module->module_code)->create(['enabled' => false]);
        PlanModule::factory()->forPlan($planB)->forModuleCode($module->module_code)->create(['enabled' => true]);
        $orgLicense = OrgLicense::factory()->forOrganization($organization)->create(['plan_id' => $planB->id]);

        $this->service->syncPlanEntitlements($firm, $planA);
        $this->service->syncOrgInheritedEntitlements($firm, $orgLicense);

        $resolution = $this->entitlementService->resolve($firm->id, 'email');

        $this->assertTrue($resolution->enabled);
        $this->assertSame(EntitlementSource::OrgInherited, $resolution->source);
    }

    /**
     * hotfix 01: reuses a module_catalog row already seeded by the
     * Phase 6 data migration instead of creating a duplicate via
     * ModuleCatalog::factory()->create(['module_code' => ...]), which
     * now violates module_catalog's unique index.
     */
    private function module(string $code): ModuleCatalog
    {
        return ModuleCatalog::query()->where('module_code', $code)->firstOrFail();
    }
}
