<?php

namespace Database\Factories;

use App\Enums\GeneratedDocumentEventType;
use App\Models\FirmUser;
use App\Models\GeneratedDocument;
use App\Models\GeneratedDocumentEvent;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<GeneratedDocumentEvent>
 */
class GeneratedDocumentEventFactory extends Factory
{
    protected $model = GeneratedDocumentEvent::class;

    /**
     * generated_document_events has permanent FORCE ROW LEVEL SECURITY
     * (see database/migrations/2026_08_27_950031_prepare_row_level_
     * security_and_force_rls_on_generated_document_events_table.php), so
     * every INSERT (test or app) must run under the row's own
     * app.current_firm_id context. See MatterExpenseFactory::create()'s
     * docblock for the full rationale, including why
     * setDatabaseTenantContextForFirmId() is used instead of
     * setFirmContext()/runWithFirmContext() and why the setting is
     * deliberately left active rather than cleared.
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
     * The generated_document_events row is always tied to the SAME firm
     * as its OWN parent generated_document — one authoritative document
     * is created up front (rather than resolving firm_id via a
     * GeneratedDocument::query()->find($id)->firm_id self-query, which
     * would fail closed under FORCE RLS with no context yet active) and
     * firm_id is derived directly from it, matching forDocument()'s own
     * already-correct logic below. A bare generated_document_events row
     * whose firm_id disagrees with its own generated_document_id's
     * parent firm is exactly the transitive cross-firm mismatch
     * documented as a known, deliberately-deferred gap in this table's
     * FORCE migration (no composite FK/trigger enforces it at the
     * database layer) — the factory must not manufacture that invalid
     * shape by default just because RLS itself cannot catch it.
     */
    /**
     * Audit fix (eager-factory-side-effects audit): this used to call
     * GeneratedDocument::factory()->create() as a plain PHP statement at
     * the top of definition() — a real, committed GeneratedDocument
     * every single time, even when forDocument() below immediately
     * overrides generated_document_id/firm_id/actor_firm_user_id with a
     * caller-supplied document. Fixed by memoizing the document behind
     * lazy closures so nothing is created unless it survives,
     * unoverridden, to the final row. actor_firm_user_id already used
     * the correct lazy-closure form and is unchanged apart from that.
     */
    private ?GeneratedDocument $lazyDocument = null;

    public function definition(): array
    {
        $this->lazyDocument = null;

        return [
            'generated_document_id' => function () {
                $this->lazyDocument ??= GeneratedDocument::factory()->create();

                return $this->lazyDocument->id;
            },
            'firm_id' => function () {
                $this->lazyDocument ??= GeneratedDocument::factory()->create();

                return $this->lazyDocument->firm_id;
            },
            'event_type' => GeneratedDocumentEventType::MarkedReadyForReview->value,
            'actor_firm_user_id' => fn (array $attributes) => FirmUser::factory()->create(['firm_id' => $attributes['firm_id']])->id,
            'created_at' => now(),
        ];
    }

    public function forDocument(GeneratedDocument $document): static
    {
        return $this->state(fn () => [
            'generated_document_id' => $document->id,
            'firm_id' => $document->firm_id,
            'actor_firm_user_id' => FirmUser::factory()->create(['firm_id' => $document->firm_id])->id,
        ]);
    }
}
