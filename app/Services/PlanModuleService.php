<?php

namespace App\Services;

use App\Enums\PlanModuleStatus;
use App\Models\ModuleCatalog;
use App\Models\Plan;
use App\Models\PlanModule;
use App\Models\PlatformAdmin;
use InvalidArgumentException;

/**
 * PlanModuleService — enable/disable module_catalog modules for a
 * Plan. is_addon = true models an optional paid add-on (approved
 * decision: no separate add-ons table). This service only manages the
 * PLAN'S module rows — it never writes firm_entitlements directly;
 * EntitlementPlanSyncService reads plan_modules and writes
 * firm_entitlements when a firm's license is assigned this plan.
 *
 * Phase 3 (FirmsVault Platform Admin Control Center, "Billing and
 * Commercial Administration") addition: setEnabled()/retire() now
 * accept an optional PlatformAdmin $actor and, when one is supplied,
 * record a PlatformAdminAuditEventRecorder::recordPlatformEvent() row
 * (the firm-less variant — a PlanModule is not tied to one firm). When
 * $actor is null (every existing caller — no app-level call site
 * currently passes one; only tests call these methods directly today)
 * behavior is byte-for-byte unchanged from before this addition.
 *
 * FIRMSVAULT — STAGING ADMIN STABILIZATION addition: addModule() now
 * validates $moduleCode against the authoritative module_catalog
 * registry (must exist and be is_active) before writing, so a Filament
 * form can never introduce a free-text/invented module code — the
 * plan_modules.module_code column already carries a real FK to
 * module_catalog.module_code, so an invalid code would previously have
 * surfaced only as a raw SQL foreign-key-violation error; this raises
 * that check to a clean, actor-facing InvalidArgumentException instead.
 * addModule() also now accepts an optional $actor and audits the
 * mutation, matching setEnabled()/retire(). Still routes through
 * updateOrCreate() keyed on (plan_id, module_code), so a duplicate
 * plan/module submission updates the existing row rather than ever
 * creating a second one.
 */
class PlanModuleService
{
    private const AUDIT_CATEGORY = 'platform_billing';

    public function __construct(
        private readonly PlatformAdminAuditEventRecorder $auditRecorder = new PlatformAdminAuditEventRecorder,
    ) {}

    public function addModule(
        Plan $plan,
        string $moduleCode,
        bool $enabled = true,
        bool $isAddon = false,
        ?PlatformAdmin $actor = null,
    ): PlanModule {
        $catalogEntry = ModuleCatalog::query()->where('module_code', $moduleCode)->first();

        if ($catalogEntry === null) {
            throw new InvalidArgumentException("\"{$moduleCode}\" is not a recognized module code.");
        }

        if (! $catalogEntry->is_active) {
            throw new InvalidArgumentException("Module \"{$moduleCode}\" is not currently active in the module catalog.");
        }

        $planModule = PlanModule::query()->updateOrCreate(
            ['plan_id' => $plan->id, 'module_code' => $moduleCode],
            ['enabled' => $enabled, 'is_addon' => $isAddon, 'status' => PlanModuleStatus::Active]
        );

        if ($actor !== null) {
            $this->auditRecorder->recordPlatformEvent(
                $actor,
                'plan_module_added',
                self::AUDIT_CATEGORY,
                [
                    'plan_module_id' => $planModule->id,
                    'plan_id' => $planModule->plan_id,
                    'module_code' => $planModule->module_code,
                    'is_addon' => $planModule->is_addon,
                    'enabled' => $planModule->enabled,
                ],
            );
        }

        return $planModule;
    }

    public function setEnabled(PlanModule $planModule, bool $enabled, ?PlatformAdmin $actor = null): PlanModule
    {
        $updated = tap($planModule)->update(['enabled' => $enabled])->fresh();

        if ($actor !== null) {
            $this->auditRecorder->recordPlatformEvent(
                $actor,
                $enabled ? 'plan_module_enabled' : 'plan_module_disabled',
                self::AUDIT_CATEGORY,
                [
                    'plan_module_id' => $updated->id,
                    'plan_id' => $updated->plan_id,
                    'module_code' => $updated->module_code,
                    'enabled' => $updated->enabled,
                ],
            );
        }

        return $updated;
    }

    public function retire(PlanModule $planModule, ?PlatformAdmin $actor = null): PlanModule
    {
        $retired = tap($planModule)->update(['status' => PlanModuleStatus::Retired, 'enabled' => false])->fresh();

        if ($actor !== null) {
            $this->auditRecorder->recordPlatformEvent(
                $actor,
                'plan_module_retired',
                self::AUDIT_CATEGORY,
                [
                    'plan_module_id' => $retired->id,
                    'plan_id' => $retired->plan_id,
                    'module_code' => $retired->module_code,
                ],
            );
        }

        return $retired;
    }
}
