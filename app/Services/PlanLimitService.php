<?php

namespace App\Services;

use App\Enums\PlanLimitMetric;
use App\Models\Plan;
use App\Models\PlanLimit;

/**
 * PlanLimitService — the only place plan_limits rows are created or
 * changed. A null $value means unlimited for that metric on that plan.
 */
class PlanLimitService
{
    public function setLimit(Plan $plan, PlanLimitMetric $metric, ?int $value): PlanLimit
    {
        return PlanLimit::query()->updateOrCreate(
            ['plan_id' => $plan->id, 'metric' => $metric->value],
            ['limit_value' => $value]
        );
    }

    public function limitFor(Plan $plan, PlanLimitMetric $metric): ?PlanLimit
    {
        return $plan->limits()->where('metric', $metric->value)->first();
    }

    /**
     * Null return means unlimited OR no limit row exists at all for
     * this metric — callers that need to distinguish "no row" from
     * "explicitly unlimited" should use limitFor() instead.
     */
    public function limitValue(Plan $plan, PlanLimitMetric $metric): ?int
    {
        return $this->limitFor($plan, $metric)?->limit_value;
    }
}
