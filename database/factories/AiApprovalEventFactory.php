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

        $service = new TenantContextService();

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $service->setDatabaseTenantContextForFirmId($group->first()->firm_id);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }

    /**
     * The ai_approval_events row is always tied to the SAME firm as its
     * OWN parent ai_approval_request — one authoritative request is
     * created up front (rather than letting ai_approval_request_id and
     * firm_id resolve as two independent AiApprovalRequest::factory()/
     * Firm::factory() calls, which is exactly what the previous version
     * of this method did) and firm_id is derived directly from it,
     * matching forRequest()'s own already-correct logic below. A bare
     * ai_approval_events row whose firm_id disagrees with its own
     * ai_approval_request_id's parent firm is exactly the transitive
     * cross-firm mismatch documented as a known, deliberately-deferred
     * gap in this table's FORCE migration (no composite FK/trigger
     * enforces it at the database layer) — the factory must not
     * manufacture that invalid shape by default just because RLS itself
     * cannot catch it.
     */
    public function definition(): array
    {
        $request = AiApprovalRequest::factory()->create();

        return [
            'ai_approval_request_id' => $request->id,
            'firm_id' => $request->firm_id,
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
