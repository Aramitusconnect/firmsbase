<?php

namespace App\Enums;

/**
 * PlanModuleStatus — plan_modules.status. Tracks the module row's own
 * lifecycle independent of its enabled boolean (e.g. a module can be
 * Retired from a plan without deleting history of it having existed on
 * that plan). Proposed during Phase 6 planning and approved.
 */
enum PlanModuleStatus: string
{
    case Active = 'active';
    case Retired = 'retired';
}
