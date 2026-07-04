<?php

namespace App\Services;

use App\Enums\CommissionPlanStatus;
use App\Models\CommissionPlan;
use App\Models\Plan;

class CommissionPlanService
{
    public function create(array $attributes): CommissionPlan
    {
        return CommissionPlan::create([
            'name' => $attributes['name'],
            'plan_id' => $attributes['plan_id'] ?? null,
            'rate_type' => $attributes['rate_type'],
            'rate_value' => $attributes['rate_value'],
            'holding_period_days' => $attributes['holding_period_days'] ?? 30,
            'status' => CommissionPlanStatus::Draft,
            'starts_at' => $attributes['starts_at'] ?? null,
            'ends_at' => $attributes['ends_at'] ?? null,
        ]);
    }

    public function activate(CommissionPlan $commissionPlan): CommissionPlan
    {
        $commissionPlan->update(['status' => CommissionPlanStatus::Active]);

        return $commissionPlan->fresh();
    }

    public function archive(CommissionPlan $commissionPlan): CommissionPlan
    {
        $commissionPlan->update(['status' => CommissionPlanStatus::Archived]);

        return $commissionPlan->fresh();
    }

    public function forPlan(Plan $plan): CommissionPlan|null
    {
        return CommissionPlan::query()
            ->where('plan_id', $plan->id)
            ->where('status', CommissionPlanStatus::Active->value)
            ->first();
    }
}
