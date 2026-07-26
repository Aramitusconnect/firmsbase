<?php

namespace Database\Factories;

use App\Enums\AiApprovalEventType;
use App\Models\AiApprovalEvent;
use App\Models\AiApprovalRequest;
use App\Models\Firm;
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
     * OWN parent ai_approval_request.
     *
     * Audit fix (eager-factory-side-effects audit, second pass): the
     * previous fix here (see this file's git history) memoized the
     * request behind a private $lazyRequest property that BOTH
     * ai_approval_request_id AND firm_id derived from — but firm_id was
     * one of the derived keys, not the source of truth. A caller
     * overriding ONLY firm_id (e.g.
     * AiApprovalEvent::factory()->create(['firm_id' => $firm->id]), the
     * exact pattern AiApprovalEventAppendOnlyTest uses, never routed
     * through forRequest()) left the ai_approval_request_id closure
     * completely unaware of the override: it still ran
     * AiApprovalRequest::factory()->create() with no override of its
     * own, eagerly creating a real, wasted, UNRELATED AiApprovalRequest
     * (+ its own nested Firm/AiUsageEvent/TenantEncryptionKey cascade —
     * see AiApprovalRequestFactory's own definition()) and left the row
     * referencing that wrong request instead of the caller's real firm
     * — a leak AND a firm_id/ai_approval_request_id ownership mismatch.
     * Fixed by making firm_id Laravel's own lazy factory-relationship
     * form (the single source of truth, resolved first) and deriving
     * ai_approval_request_id from the already-resolved
     * $attributes['firm_id'] via a lazy closure — mirrors
     * PaymentPlanEventFactory's identical $attributes-derivation
     * convention, so ANY override of firm_id (bare or via forRequest())
     * is automatically observed.
     */
    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'ai_approval_request_id' => fn (array $attributes) => AiApprovalRequest::factory()
                ->forFirm(Firm::query()->findOrFail($attributes['firm_id'])),
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
