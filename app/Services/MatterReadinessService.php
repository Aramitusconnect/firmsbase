<?php

namespace App\Services;

use App\Enums\MatterReadinessStatus;
use App\Models\Matter;
use App\Models\MatterReadinessScore;

/**
 * MatterReadinessService — recomputes and persists exactly one
 * matter_readiness_scores row per matter, using ONLY the components
 * ReadinessScorecardRegistry currently evaluates (project rule:
 * "Readiness must work only with components that currently exist").
 * Never requires AI (project rule).
 */
class MatterReadinessService
{
    public function __construct(private ReadinessScorecardRegistry $registry)
    {
    }

    public function recompute(Matter $matter): MatterReadinessScore
    {
        $results = $this->registry->evaluate($matter);

        $satisfiedCount = count(array_filter($results, fn ($r) => $r->satisfied));
        $totalCount = count($results);

        $status = match (true) {
            $totalCount === 0 => MatterReadinessStatus::NotReady,
            $satisfiedCount === $totalCount => MatterReadinessStatus::Ready,
            $satisfiedCount > 0 => MatterReadinessStatus::PartiallyReady,
            default => MatterReadinessStatus::NotReady,
        };

        $breakdown = array_map(fn ($r) => [
            'component_key' => $r->componentKey,
            'satisfied' => $r->satisfied,
            'detail' => $r->detail,
        ], $results);

        $score = MatterReadinessScore::query()->firstOrNew(['matter_id' => $matter->id]);
        $previousStatus = $score->exists ? $score->status?->value : null;

        $score->fill([
            'firm_id' => $matter->firm_id,
            'matter_id' => $matter->id,
            'status' => $status,
            'satisfied_count' => $satisfiedCount,
            'total_count' => $totalCount,
            'breakdown_json' => $breakdown,
            'computed_at' => now(),
        ])->save();

        $score->matter()->first(); // ensure relation loadable, no-op otherwise

        \App\Models\ReadinessScoreEvent::create([
            'firm_id' => $matter->firm_id,
            'matter_id' => $matter->id,
            'event_type' => 'recomputed',
            'previous_status' => $previousStatus,
            'new_status' => $status->value,
            'metadata_json' => ['satisfied_count' => $satisfiedCount, 'total_count' => $totalCount],
        ]);

        return $score->fresh();
    }
}
