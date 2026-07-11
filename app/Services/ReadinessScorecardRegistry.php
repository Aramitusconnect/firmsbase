<?php

namespace App\Services;

use App\Enums\DocumentRequestItemStatus;
use App\Enums\MatterStatus;
use App\Enums\ReadinessComponentStatus;
use App\Enums\TaskStatus;
use App\Models\Matter;
use App\Models\ReadinessScorecardComponent;
use App\ValueObjects\ReadinessComponentResult;

/**
 * ReadinessScorecardRegistry — the pluggable core of Phase 4's
 * readiness scoring. A component is evaluated only when BOTH true:
 *   1. an evaluator callable is registered here for its component_key
 *   2. its readiness_scorecard_components catalog row has
 *      status = Active
 * Registering a brand-new component (e.g. Phase 10's forms_ready,
 * Phase 11's signatures_complete, or fees_paid as Phases 3/6 mature)
 * requires only: a new catalog row (data, not schema) + a call to
 * register() with an evaluator closure — never a migration. This is
 * exactly what the acceptance criterion "new components can register
 * without schema change" means, and this class is the thing a test
 * exercises to prove it.
 *
 * Constructor self-registers the 4 components this phase's Scope text
 * names as available now: intake_complete, documents_approved,
 * tasks_dependencies_ready, attorney_review_status. Each evaluator
 * reads only fields that already exist on the Phase 1-4 schema — no
 * AI, no new columns.
 */
class ReadinessScorecardRegistry
{
    /**
     * @var array<string, callable(Matter): ReadinessComponentResult>
     */
    private array $evaluators = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    /**
     * @param  callable(Matter): ReadinessComponentResult  $evaluator
     */
    public function register(string $componentKey, callable $evaluator): void
    {
        $this->evaluators[$componentKey] = $evaluator;
    }

    public function isRegistered(string $componentKey): bool
    {
        return isset($this->evaluators[$componentKey]);
    }

    /**
     * @return array<int, ReadinessComponentResult>
     */
    public function evaluate(Matter $matter): array
    {
        $activeKeys = ReadinessScorecardComponent::query()
            ->where('status', ReadinessComponentStatus::Active->value)
            ->pluck('component_key')
            ->all();

        $results = [];

        foreach ($activeKeys as $key) {
            if (! $this->isRegistered($key)) {
                continue;
            }

            $results[] = ($this->evaluators[$key])($matter);
        }

        return $results;
    }

    private function registerDefaults(): void
    {
        $this->register('intake_complete', function (Matter $matter): ReadinessComponentResult {
            $submissions = $matter->intakeSubmissions;

            if ($submissions->isEmpty()) {
                return new ReadinessComponentResult('intake_complete', true, 'no intake required for this matter');
            }

            $latest = $submissions->sortByDesc('id')->first();
            $satisfied = $latest->status->value === 'reviewed';

            return new ReadinessComponentResult('intake_complete', $satisfied, "latest intake status: {$latest->status->value}");
        });

        $this->register('documents_approved', function (Matter $matter): ReadinessComponentResult {
            $outstanding = (new TenantContextService())->runWithFirmContext($matter->firm_id, fn () => \App\Models\DocumentRequestItem::query()
                ->whereHas('documentRequest', fn ($q) => $q->where('matter_id', $matter->id))
                ->where('is_required', true)
                ->whereNotIn('status', [
                    DocumentRequestItemStatus::Approved->value,
                    DocumentRequestItemStatus::Waived->value,
                ])
                ->count());

            return new ReadinessComponentResult(
                'documents_approved',
                $outstanding === 0,
                $outstanding === 0 ? 'all required documents approved or waived' : "{$outstanding} required document(s) still outstanding"
            );
        });

        $this->register('tasks_dependencies_ready', function (Matter $matter): ReadinessComponentResult {
            $unresolved = (new TenantContextService())->runWithFirmContext($matter->firm_id, fn () => \App\Models\Task::query()
                ->where('matter_id', $matter->id)
                ->whereNotIn('status', [TaskStatus::Completed->value, TaskStatus::Cancelled->value])
                ->count());

            return new ReadinessComponentResult(
                'tasks_dependencies_ready',
                $unresolved === 0,
                $unresolved === 0 ? 'no open or blocked tasks' : "{$unresolved} task(s) still open or blocked"
            );
        });

        $this->register('attorney_review_status', function (Matter $matter): ReadinessComponentResult {
            $hasAttorney = ! is_null($matter->assigned_attorney_id);
            $reachedReview = in_array($matter->status, [
                MatterStatus::ReadyForReview,
                MatterStatus::FiledSubmitted,
                MatterStatus::Closed,
                MatterStatus::Archived,
            ], true);

            $satisfied = $hasAttorney && $reachedReview;

            return new ReadinessComponentResult(
                'attorney_review_status',
                $satisfied,
                $satisfied ? 'attorney assigned and matter has reached review' : 'awaiting attorney assignment or review'
            );
        });
    }
}
