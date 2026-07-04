<?php

namespace App\Services;

use App\Enums\PlanStatus;
use App\Models\Plan;

/**
 * PlanService — the only place Plan rows are created or have their
 * lifecycle status changed. Plans are global reference/commercial data
 * (no firm_id), edited by platform admins only.
 */
class PlanService
{
    public function create(array $attributes): Plan
    {
        return Plan::create(array_merge(['status' => PlanStatus::Draft, 'is_active' => true], $attributes));
    }

    public function update(Plan $plan, array $attributes): Plan
    {
        return tap($plan)->update($attributes)->fresh();
    }

    public function activate(Plan $plan): Plan
    {
        return tap($plan)->update(['status' => PlanStatus::Active])->fresh();
    }

    public function archive(Plan $plan): Plan
    {
        return tap($plan)->update(['status' => PlanStatus::Archived, 'is_active' => false])->fresh();
    }
}
