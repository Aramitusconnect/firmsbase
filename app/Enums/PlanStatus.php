<?php

namespace App\Enums;

/**
 * PlanStatus — plans.status. Draft plans can be edited freely; Active
 * plans can be assigned to new licenses; Archived plans remain
 * referenced by existing licenses/history but cannot be newly assigned.
 * Proposed during Phase 6 planning and approved.
 */
enum PlanStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
