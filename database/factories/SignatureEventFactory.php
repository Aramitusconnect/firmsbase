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

        $service = new TenantContextService;

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $service->setDatabaseTenantContextForFirmId($group->first()->firm_id);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }

    /**
     * The signature_events row is always tied to the SAME firm as its
     * OWN parent signature_request.
     *
     * Audit fix (eager-factory-side-effects audit): this used to call
     * SignatureRequest::factory()->create() as a plain PHP statement at
     * the top of definition(), and FirmUser::factory()->create(...)
     * directly as an array value (also eager) — real, committed rows
     * every single time, even when forRequest() below immediately
     * overrides ALL THREE of signature_request_id/firm_id/
     * actor_firm_user_id with caller-supplied values. Fixed by
     * memoizing the request behind lazy closures, and making
     * actor_firm_user_id itself a lazy closure keyed off the
     * already-resolved firm_id, so nothing is created unless it
     * survives, unoverridden, to the final row.
     */
    private ?SignatureRequest $lazyRequest = null;

    public function definition(): array
    {
        $this->lazyRequest = null;

        return [
            'signature_request_id' => function () {
                $this->lazyRequest ??= SignatureRequest::factory()->create();

                return $this->lazyRequest->id;
            },
            'firm_id' => function () {
                $this->lazyRequest ??= SignatureRequest::factory()->create();

                return $this->lazyRequest->firm_id;
            },
            'event_type' => SignatureEventType::RequestCreated->value,
            'actor_type' => SignatureEventActorType::FirmUser->value,
            'actor_firm_user_id' => fn (array $attributes) => FirmUser::factory()
                ->create(['firm_id' => $attributes['firm_id']])
                ->id,
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
