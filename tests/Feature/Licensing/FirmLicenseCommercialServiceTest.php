<?php

namespace Tests\Feature\Licensing;

use App\Enums\BillingMode;
use App\Enums\EntitlementSource;
use App\Enums\LicenseStatus;
use App\Models\Firm;
use App\Models\FirmLicense;
use App\Models\LicenseEvent;
use App\Models\ModuleCatalog;
use App\Models\Organization;
use App\Models\OrgLicense;
use App\Models\Plan;
use App\Services\EntitlementPlanSyncService;
use App\Services\EntitlementService;
use App\Services\FirmLicenseCommercialService;
use App\Services\OrgLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FirmLicenseCommercialServiceTest extends TestCase
{
    use RefreshDatabase;

    private FirmLicenseCommercialService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FirmLicenseCommercialService(new EntitlementPlanSyncService(new EntitlementService()));
    }

    public function test_assign_plan_updates_the_license_and_syncs_plan_entitlements(): void
    {
        $firm = Firm::factory()->create();
        $license = FirmLicense::factory()->create(['firm_id' => $firm->id]);
        $plan = Plan::factory()->create();
        $module = $this->module('ai');
        \App\Models\PlanModule::factory()->forPlan($plan)->forModuleCode($module->module_code)->create(['enabled' => true]);

        $updated = $this->service->assignPlan($license, $plan, billingMode: BillingMode::SelfService);

        $this->assertSame($plan->id, $updated->plan_id);
        $this->assertSame(BillingMode::SelfService, $updated->billing_mode);

        // Section 39A-3L, Checkpoint 4 — firm_entitlements now has FORCE
        // ROW LEVEL SECURITY active. assertDatabaseHas() queries with no
        // tenant context of its own, so it would now see zero rows.
        // assignPlan() -> EntitlementService::setForSource() already
        // cleared context by the time control returns here, so this is
        // a genuinely fresh, explicitly context-wrapped read.
        $this->runWithFirmContext($firm, function () use ($firm) {
            $this->assertDatabaseHas('firm_entitlements', [
                'firm_id' => $firm->id,
                'module_code' => 'ai',
                'source' => EntitlementSource::Plan->value,
                'enabled' => true,
            ]);
        });

        $event = LicenseEvent::query()
            ->where('licensable_type', FirmLicense::class)
            ->where('licensable_id', $license->id)
            ->where('event_type', 'plan_assigned')
            ->first();

        $this->assertNotNull($event);
    }

    public function test_assign_plan_with_an_org_license_syncs_org_inherited_entitlements_instead_of_plan(): void
    {
        $organization = Organization::factory()->create();
        $firm = Firm::factory()->create(['organization_id' => $organization->id]);
        $license = FirmLicense::factory()->create(['firm_id' => $firm->id]);
        $plan = Plan::factory()->create();
        $module = $this->module('reports');
        \App\Models\PlanModule::factory()->forPlan($plan)->forModuleCode($module->module_code)->create(['enabled' => true]);

        $orgLicense = (new OrgLicenseService())->issue($organization, $plan);

        $this->service->assignPlan($license, $plan, orgLicense: $orgLicense);

        // Section 39A-3L, Checkpoint 4 — same reasoning as
        // test_assign_plan_updates_the_license_and_syncs_plan_entitlements
        // above. Both assertions are wrapped in the SAME explicit
        // context: the assertDatabaseMissing() below must genuinely
        // prove "no plan-sourced row exists for this firm/module", not
        // merely benefit from the fail-closed "no context = zero rows"
        // behavior that would make it vacuously true if left unwrapped.
        $this->runWithFirmContext($firm, function () use ($firm) {
            $this->assertDatabaseHas('firm_entitlements', [
                'firm_id' => $firm->id,
                'module_code' => 'reports',
                'source' => EntitlementSource::OrgInherited->value,
                'enabled' => true,
            ]);

            $this->assertDatabaseMissing('firm_entitlements', [
                'firm_id' => $firm->id,
                'module_code' => 'reports',
                'source' => EntitlementSource::Plan->value,
            ]);
        });
    }

    public function test_change_status_logs_from_and_to_status(): void
    {
        $license = FirmLicense::factory()->create(['license_status' => LicenseStatus::Trial]);

        $updated = $this->service->changeStatus($license, LicenseStatus::Active, 'activated after payment');

        $this->assertSame(LicenseStatus::Active, $updated->license_status);

        $event = LicenseEvent::query()
            ->where('licensable_type', FirmLicense::class)
            ->where('licensable_id', $license->id)
            ->first();

        $this->assertSame('trial', $event->from_status);
        $this->assertSame('active', $event->to_status);
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
