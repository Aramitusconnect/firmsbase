<?php

namespace App\Services;

use App\Enums\MatterReadinessStatus;
use App\Enums\WebhookEventType;
use App\Models\Matter;
use App\Models\MatterReadinessScore;
use Illuminate\Support\Facades\DB;

/**
 * MatterReadinessService — recomputes and persists exactly one
 * matter_readiness_scores row per matter, using ONLY the components
 * ReadinessScorecardRegistry currently evaluates (project rule:
 * "Readiness must work only with components that currently exist").
 * Never requires AI (project rule).
 *
 * Phase 14b addition: recompute() fires matter.readiness_changed only
 * when the status actually changes ($previousStatus !== $status->value)
 * — NOT on every call, unlike the unconditional ReadinessScoreEvent
 * audit row created below. The webhook subject is always the
 * MatterReadinessScore itself, never ReadinessScoreEvent (which lacks
 * satisfied_count/total_count and would over-fire). Not wrapped in an
 * explicit DB::transaction(); DB::afterCommit() runs the closure
 * immediately since there is no active transaction to defer past.
 */
class MatterReadinessService
{
    public function __construct(private ReadinessScorecardRegistry $registry)
    {
    }

    public function recompute(Matter $matter): MatterReadinessScore
    {
        // Section 39A-3L, Checkpoint 14: evaluate() through the
        // fresh() reload of the SCORE is wrapped in ONE tenant-context
        // call, including the firstOrNew() lookup itself. The lookup
        // must be inside the wrap: under FORCE RLS, an unscoped SELECT
        // with no active context returns zero rows even when a score
        // row already exists for this matter, which would make
        // firstOrNew() attempt a duplicate INSERT against the
        // matter_id unique constraint on the second recompute() call.
        // The previous "decoy wrap" (a throwaway no-op read) is gone —
        // it wrapped nothing that mattered while the real persistence
        // ran unwrapped. A naive whole-method wrap was tried first and
        // empirically failed: registry->evaluate() calls two evaluators
        // that used to self-wrap internally, and each one's own
        // finally-block teardown cleared this outer context before the
        // writes below ran. Those two evaluators no longer self-wrap
        // (see ReadinessScorecardRegistry), so this single outer wrap
        // is now the only context established for the whole read+write
        // sequence.
        // Section 39A-3L, Checkpoint 15 (incremental): the
        // readiness_score_events write, previously left outside this
        // wrap because that table wasn't forced yet, now moves inside
        // it — readiness_score_events is FORCE RLS as of this
        // checkpoint's own migration, so an unwrapped insert would now
        // fail the same way the score's own write would have under
        // Checkpoint 14.
        [$freshScore, $previousStatus, $status] = (new TenantContextService())->runWithFirmContext(
            $matter->firm_id,
            function () use ($matter) {
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

                \App\Models\ReadinessScoreEvent::create([
                    'firm_id' => $matter->firm_id,
                    'matter_id' => $matter->id,
                    'event_type' => 'recomputed',
                    'previous_status' => $previousStatus,
                    'new_status' => $status->value,
                    'metadata_json' => ['satisfied_count' => $satisfiedCount, 'total_count' => $totalCount],
                ]);

                return [$score->fresh(), $previousStatus, $status];
            }
        );

        // Deliberately OUTSIDE the wrap above and UNCHANGED:
        // runWithFirmContext() opens its own internal DB::transaction(),
        // so moving this afterCommit scheduling inside it would defer
        // firing to that inner transaction's commit instead of firing
        // immediately, contradicting this method's own documented
        // behavior (no active transaction to defer past).
        if ($previousStatus !== $status->value) {
            DB::afterCommit(function () use ($matter, $freshScore) {
                try {
                    app(WebhookEventRecorderService::class)->record($matter->firm, WebhookEventType::MatterReadinessChanged, $freshScore);
                } catch (\Throwable $e) {
                    report($e);
                }
            });
        }

        return $freshScore;
    }
}
