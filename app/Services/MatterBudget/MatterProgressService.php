<?php

namespace App\Services\MatterBudget;

use App\Enums\DocumentRequestItemStatus;
use App\Enums\TaskStatus;
use App\Models\DocumentRequestItem;
use App\Models\Matter;
use App\Models\Task;

/**
 * MatterProgressService — Predictive Matter Budget Alerts, item 9.
 * Deterministic work-completion percentage for a Matter, derived ONLY
 * from structured work that already exists (Tasks, DocumentRequestItem
 * required/submitted state) — never an LLM-invented number, never a
 * meaningless universal figure.
 *
 * Two components, each its own ratio:
 * - tasks: completed / (all non-cancelled tasks on the matter)
 * - document_requirements: satisfied (Approved or Waived) / (all
 *   is_required=true items across the matter's document requests)
 *
 * A component with zero eligible items is simply absent from the
 * average (not counted as 0%) — a Matter with no document requests at
 * all should not be penalized for lacking a component that was never
 * applicable. If NEITHER component has any items, completion is 0 (a
 * brand-new matter has done no structured work yet, deterministically).
 *
 * Equal weighting between the two present components — the master
 * spec's own "weighted completion may be supported ONLY if the
 * Firm/template explicitly defines weights" is a MISSING capability in
 * this pass (no weight field exists anywhere in this schema); equal
 * weighting is the correct, honest default until one is added.
 */
class MatterProgressService
{
    /**
     * @return array{completion_percent: int, breakdown: array<string, mixed>}
     */
    public function compute(Matter $matter): array
    {
        $taskRatio = $this->taskRatio($matter);
        $documentRatio = $this->documentRequirementRatio($matter);

        $ratios = array_filter([$taskRatio, $documentRatio], fn ($r) => $r !== null);
        $completionPercent = empty($ratios) ? 0 : (int) round((array_sum(array_map(fn ($r) => $r['ratio'], $ratios)) / count($ratios)) * 100);

        return [
            'completion_percent' => $completionPercent,
            'breakdown' => [
                'tasks' => $taskRatio,
                'document_requirements' => $documentRatio,
            ],
        ];
    }

    /**
     * @return array{completed: int, total: int, ratio: float}|null
     */
    private function taskRatio(Matter $matter): ?array
    {
        $total = Task::query()
            ->where('matter_id', $matter->id)
            ->where('status', '!=', TaskStatus::Cancelled->value)
            ->count();

        if ($total === 0) {
            return null;
        }

        $completed = Task::query()
            ->where('matter_id', $matter->id)
            ->where('status', TaskStatus::Completed->value)
            ->count();

        return ['completed' => $completed, 'total' => $total, 'ratio' => $completed / $total];
    }

    /**
     * @return array{completed: int, total: int, ratio: float}|null
     */
    private function documentRequirementRatio(Matter $matter): ?array
    {
        $total = DocumentRequestItem::query()
            ->whereHas('documentRequest', fn ($q) => $q->where('matter_id', $matter->id))
            ->where('is_required', true)
            ->count();

        if ($total === 0) {
            return null;
        }

        $completed = DocumentRequestItem::query()
            ->whereHas('documentRequest', fn ($q) => $q->where('matter_id', $matter->id))
            ->where('is_required', true)
            ->whereIn('status', [DocumentRequestItemStatus::Approved->value, DocumentRequestItemStatus::Waived->value])
            ->count();

        return ['completed' => $completed, 'total' => $total, 'ratio' => $completed / $total];
    }
}
