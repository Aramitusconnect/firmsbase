<?php

namespace Database\Factories;

use App\Enums\FormReviewEventType;
use App\Models\FirmUser;
use App\Models\FormDraft;
use App\Models\FormReviewEvent;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<FormReviewEvent>
 */
class FormReviewEventFactory extends Factory
{
    protected $model = FormReviewEvent::class;

    /**
     * form_review_events has permanent FORCE ROW LEVEL SECURITY (see
     * database/migrations/2026_08_27_950032_prepare_row_level_security_
     * and_force_rls_on_form_review_events_table.php), so every INSERT
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
     * The form_review_events row is always tied to the SAME firm as its
     * OWN parent form_draft — one authoritative draft is created up
     * front (rather than resolving firm_id via a
     * FormDraft::query()->find($id)->firm_id self-query, which would
     * fail closed under FORCE RLS with no context yet active) and
     * firm_id is derived directly from it, matching forDraft()'s own
     * already-correct logic below. A bare form_review_events row whose
     * firm_id disagrees with its own form_draft_id's parent firm is
     * exactly the transitive cross-firm mismatch documented as a known,
     * deliberately-deferred gap in this table's FORCE migration (no
     * composite FK/trigger enforces it at the database layer) — the
     * factory must not manufacture that invalid shape by default just
     * because RLS itself cannot catch it.
     */
    public function definition(): array
    {
        $draft = FormDraft::factory()->create();

        return [
            'form_draft_id' => $draft->id,
            'firm_id' => $draft->firm_id,
            'event_type' => FormReviewEventType::MarkedReadyForReview->value,
            'actor_firm_user_id' => fn (array $attributes) => FirmUser::factory()->create(['firm_id' => $attributes['firm_id']])->id,
            'created_at' => now(),
        ];
    }

    public function forDraft(FormDraft $draft): static
    {
        return $this->state(fn () => [
            'form_draft_id' => $draft->id,
            'firm_id' => $draft->firm_id,
            'actor_firm_user_id' => FirmUser::factory()->create(['firm_id' => $draft->firm_id])->id,
        ]);
    }
}
