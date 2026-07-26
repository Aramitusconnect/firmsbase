<?php

namespace Tests\Feature\Plans;

use App\Enums\PlanModuleStatus;
use App\Models\ModuleCatalog;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Services\PlanModuleService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlanModuleServiceTest extends TestCase
{
    use RefreshDatabase;

    private PlanModuleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PlanModuleService;
    }

    public function test_add_module_creates_a_row(): void
    {
        $plan = Plan::factory()->create();
        $module = $this->module('ai');

        $planModule = $this->service->addModule($plan, $module->module_code);

        $this->assertTrue($planModule->enabled);
        $this->assertFalse($planModule->is_addon);
        $this->assertSame($plan->id, $planModule->plan_id);
    }

    public function test_add_module_is_idempotent_per_plan_and_module(): void
    {
        $plan = Plan::factory()->create();
        $module = $this->module('reports');

        $first = $this->service->addModule($plan, $module->module_code, enabled: true);
        $second = $this->service->addModule($plan, $module->module_code, enabled: false);

        $this->assertSame($first->id, $second->id);
        $this->assertFalse($second->fresh()->enabled);
    }

    public function test_add_module_can_be_flagged_as_an_addon(): void
    {
        $plan = Plan::factory()->create();
        $module = $this->module('dedicated_branding');

        $planModule = $this->service->addModule($plan, $module->module_code, enabled: true, isAddon: true);

        $this->assertTrue($planModule->is_addon);
    }

    public function test_set_enabled_toggles_the_module(): void
    {
        $plan = Plan::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $planModule = $this->service->addModule($plan, $module->module_code);

        $disabled = $this->service->setEnabled($planModule, false);

        $this->assertFalse($disabled->enabled);
    }

    public function test_retire_disables_and_marks_retired(): void
    {
        $plan = Plan::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $planModule = $this->service->addModule($plan, $module->module_code);

        $retired = $this->service->retire($planModule);

        $this->assertFalse($retired->enabled);
        $this->assertSame(PlanModuleStatus::Retired, $retired->status);
    }

    // ------------------------------------------------------------
    // Phase 3 FirmsVault Admin Control Center additions — actor +
    // audit plumbing on setEnabled()/retire().
    // ------------------------------------------------------------

    public function test_set_enabled_without_an_actor_writes_no_audit_event(): void
    {
        $plan = Plan::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $planModule = $this->service->addModule($plan, $module->module_code);

        $this->service->setEnabled($planModule, false);

        $count = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')
                ->whereIn('event_type', ['plan_module_enabled', 'plan_module_disabled'])
                ->count()
        );
        $this->assertSame(0, $count);
    }

    public function test_set_enabled_false_with_an_actor_writes_a_plan_module_disabled_event(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $plan = Plan::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $planModule = $this->service->addModule($plan, $module->module_code, enabled: true);

        $disabled = $this->service->setEnabled($planModule, false, actor: $admin);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')->where('event_type', 'plan_module_disabled')->first()
        );

        $this->assertNotNull($row);
        $this->assertNull($row->firm_id);
        $this->assertSame(PlatformAdmin::class, $row->actor_type);
        $this->assertSame($admin->id, $row->actor_id);
        $this->assertSame('platform_billing', $row->category);

        $metadata = json_decode($row->metadata, true);
        $this->assertSame($disabled->id, $metadata['plan_module_id']);
        $this->assertSame($plan->id, $metadata['plan_id']);
        $this->assertSame($module->module_code, $metadata['module_code']);
        $this->assertFalse($metadata['enabled']);
    }

    public function test_set_enabled_true_with_an_actor_writes_a_plan_module_enabled_event(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $plan = Plan::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $planModule = $this->service->addModule($plan, $module->module_code, enabled: false);

        $this->service->setEnabled($planModule, true, actor: $admin);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')->where('event_type', 'plan_module_enabled')->first()
        );

        $this->assertNotNull($row);
        $metadata = json_decode($row->metadata, true);
        $this->assertTrue($metadata['enabled']);
    }

    public function test_retire_without_an_actor_writes_no_audit_event(): void
    {
        $plan = Plan::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $planModule = $this->service->addModule($plan, $module->module_code);

        $this->service->retire($planModule);

        $count = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')->where('event_type', 'plan_module_retired')->count()
        );
        $this->assertSame(0, $count);
    }

    public function test_retire_with_an_actor_writes_a_correctly_attributed_audit_event(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $plan = Plan::factory()->create();
        $module = ModuleCatalog::factory()->create();
        $planModule = $this->service->addModule($plan, $module->module_code);

        $retired = $this->service->retire($planModule, actor: $admin);

        $row = app(TenantContextService::class)->runWithoutFirmContext(
            fn () => DB::table('security_events')->where('event_type', 'plan_module_retired')->first()
        );

        $this->assertNotNull($row);
        $this->assertSame($admin->id, $row->actor_id);

        $metadata = json_decode($row->metadata, true);
        $this->assertSame($retired->id, $metadata['plan_module_id']);
        $this->assertSame($plan->id, $metadata['plan_id']);
        $this->assertSame($module->module_code, $metadata['module_code']);
        $this->assertEqualsCanonicalizing(['plan_module_id', 'plan_id', 'module_code'], array_keys($metadata));
    }

    /**
     * hotfix 01: reuses a module_catalog row already seeded by the
     * Phase 6 data migration (2026_07_09_900023_seed_phase6_module_
     * catalog_entries) instead of creating a duplicate via
     * ModuleCatalog::factory()->create(['module_code' => ...]), which
     * now violates module_catalog's unique index. firstOrFail() is
     * used (not firstOrCreate()) because the migration is expected to
     * have already seeded this exact code — a missing row here should
     * fail loudly, not silently paper over a broken seed.
     */
    private function module(string $code): ModuleCatalog
    {
        return ModuleCatalog::query()->where('module_code', $code)->firstOrFail();
    }
}
