<?php

namespace App\Services;

use App\Enums\PlanModuleStatus;
use App\Models\Plan;
use App\Models\PlanModule;

/**
 * PlanModuleService — enable/disable module_catalog modules for a
 * Plan. is_addon = true models an optional paid add-on (approved
 * decision: no separate add-ons table). This service only manages the
 * PLAN'S module rows — it never writes firm_entitlements directly;
 * EntitlementPlanSyncService reads plan_modules and writes
 * firm_entitlements when a firm's license is assigned this plan.
 */
class PlanModuleService
{
    public function addModule(Plan $plan, string $moduleCode, bool $enabled = true, bool $isAddon = false): PlanModule
    {
        return PlanModule::query()->updateOrCreate(
            ['plan_id' => $plan->id, 'module_code' => $moduleCode],
            ['enabled' => $enabled, 'is_addon' => $isAddon, 'status' => PlanModuleStatus::Active]
        );
    }

    public function setEnabled(PlanModule $planModule, bool $enabled): PlanModule
    {
        return tap($planModule)->update(['enabled' => $enabled])->fresh();
    }

    public function retire(PlanModule $planModule): PlanModule
    {
        return tap($planModule)->update(['status' => PlanModuleStatus::Retired, 'enabled' => false])->fresh();
    }
}
