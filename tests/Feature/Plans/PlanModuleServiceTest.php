<?php

namespace Tests\Feature\Plans;

use App\Models\ModuleCatalog;
use App\Models\Plan;
use App\Services\PlanModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanModuleServiceTest extends TestCase
{
    use RefreshDatabase;

    private PlanModuleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PlanModuleService();
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
        $this->assertSame(\App\Enums\PlanModuleStatus::Retired, $retired->status);
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
