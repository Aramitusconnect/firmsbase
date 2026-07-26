<?php

namespace Database\Factories;

use App\Enums\AiApprovalEventType;
use App\Models\AiApprovalEvent;
use App\Models\AiApprovalRequest;
use App\Models\User;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<AiApprovalEvent>
 */
class AiApprovalEventFactory extends Factory
{
    protected $model = AiApprovalEvent::class;

    /**
     * ai_approval_events has permanent FORCE ROW LEVEL SECURITY (see
     * database/migrations/2026_08_27_950017_prepare_row_level_security_
     * and_force_rls_on_ai_approval_events_table.php), so every INSERT
     * (test or app) must run under the row's own app.current_firm_id
     * context. See MatterExpenseFactory::create()'s docblock for the
     * full rationale, including why setDatabaseTenantContextForFirmId()
     * is used instead of setFirmContext()/runWithFirmContext() and why
     * the setting is deliberately left active rather than cleared.
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        if (! empty($attributes)) {
            return $this->state($attributes)->create([], $parent);
        }

        $results = $this->make($attributes, $parent);

        $models = $results instanceof Model ? new Collection([$results]) : $results;

        $service = new TenantContextService;

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $service->setDatabaseTenantContextForFirmId($group->first()->firm_id);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }

    /**
     * The ai_approval_events row is always tied to the SAME firm as its
     * OWN parent ai_approval_request. Audit fix (eager-factory-
     * side-effects audit): the previous version of this method called
     * AiApprovalRequest::factory()->create() as a plain PHP statement at
     * the top of definition() — a real, committed AiApprovalRequest (+
     * its own nested Firm/AiUsageEvent) every single time, even when
     * forRequest() below immediately overrides ai_approval_request_id/
     * firm_id with a caller-supplied request. Laravel cannot skip a
     * side effect that already happened while building the array; it
     * can only skip re-resolving a definition() value that is still an
     * unresolved Factory/Closure by the time a later state() overrides
     * that key. Every forRequest()-scoped create() (the normal,
     * intended way this factory is used) was therefore silently wasting
     * one real, fully-committed AiApprovalRequest per call. Fixed by
     * memoizing the request behind lazy closures (mirrors
     * IntegrationOAuthStateFactory's memoized-lazy-value convention):
     * nothing is created unless ai_approval_request_id or firm_id
     * survives, unoverridden, to the final row, and when it does, both
     * derive from the SAME request rather than two independent chains.
     */
    private ?AiApprovalRequest $lazyRequest = null;

    public function definition(): array
    {
        $this->lazyRequest = null;

        return [
            'ai_approval_request_id' => function () {
                $this->lazyRequest ??= AiApprovalRequest::factory()->create();

                return $this->lazyRequest->id;
            },
            'firm_id' => function () {
                $this->lazyRequest ??= AiApprovalRequest::factory()->create();

                return $this->lazyRequest->firm_id;
            },
            'event_type' => AiApprovalEventType::Submitted,
            'actor_id' => User::factory(),
        ];
    }

    public function forRequest(AiApprovalRequest $request): static
    {
        return $this->state(fn () => [
            'ai_approval_request_id' => $request->id,
            'firm_id' => $request->firm_id,
        ]);
    }
}
