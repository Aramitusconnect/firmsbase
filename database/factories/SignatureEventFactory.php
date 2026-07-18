<?php

namespace Database\Factories;

use App\Enums\SignatureEventActorType;
use App\Enums\SignatureEventType;
use App\Models\FirmUser;
use App\Models\SignatureEvent;
use App\Models\SignatureRequest;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<SignatureEvent>
 */
class SignatureEventFactory extends Factory
{
    protected $model = SignatureEvent::class;

    /**
     * signature_events has permanent FORCE ROW LEVEL SECURITY (see
     * database/migrations/2026_08_27_950037_prepare_row_level_security_
     * and_force_rls_on_signature_events_table.php), so every INSERT
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
     * The signature_events row is always tied to the SAME firm as its
     * OWN parent signature_request — one authoritative request is
     * created up front (rather than resolving firm_id via a
     * SignatureRequest::query()->find($id)->firm_id self-query, which
     * would fail closed under FORCE RLS with no context yet active) and
     * firm_id is derived directly from it, matching forRequest()'s own
     * already-correct logic below. A bare signature_events row whose
     * firm_id disagrees with its own signature_request_id's parent firm
     * is exactly the transitive cross-firm mismatch documented as a
     * known, deliberately-deferred gap in this table's FORCE migration
     * (no composite FK/trigger enforces it at the database layer) — the
     * factory must not manufacture that invalid shape by default just
     * because RLS itself cannot catch it.
     */
    public function definition(): array
    {
        $request = SignatureRequest::factory()->create();

        return [
            'signature_request_id' => $request->id,
            'firm_id' => $request->firm_id,
            'event_type' => SignatureEventType::RequestCreated->value,
            'actor_type' => SignatureEventActorType::FirmUser->value,
            'actor_firm_user_id' => FirmUser::factory()->create(['firm_id' => $request->firm_id])->id,
            'created_at' => now(),
        ];
    }

    public function forRequest(SignatureRequest $request): static
    {
        return $this->state(fn () => [
            'signature_request_id' => $request->id,
            'firm_id' => $request->firm_id,
            'actor_firm_user_id' => FirmUser::factory()->create(['firm_id' => $request->firm_id])->id,
        ]);
    }

    public function eventType(SignatureEventType $type): static
    {
        return $this->state(fn () => ['event_type' => $type->value]);
    }
}
