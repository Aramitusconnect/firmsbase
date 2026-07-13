<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\DocumentChaseRule;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<\App\Models\DocumentChaseEvent>
 */
class DocumentChaseEventFactory extends Factory
{
    protected $model = \App\Models\DocumentChaseEvent::class;

    /**
     * Section 39A-3L, Checkpoint 17 — context-hold pattern (matching
     * every prior FORCE-RLS factory since 39A-3A): groups resolved
     * models by firm_id and activates the matching PostgreSQL session
     * context per group before inserting, so a bare
     * DocumentChaseEvent::factory()->create() works correctly even
     * called from outside any already-active tenant context.
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
     * firm_id and document_request_item_id used to be two independent
     * random Factory chains (the same bug class as Checkpoints
     * 5/7/8/10/12/13/14/15): a bare DocumentChaseEvent::factory()->
     * create() could resolve a document_request_item belonging to a
     * DIFFERENT firm than the one written to firm_id. Fixed here by
     * building the whole chain explicitly top-down (Client ->
     * DocumentRequest -> DocumentRequestItem), keeping each created
     * object in PHP memory rather than reading it back — deliberately
     * NOT "create a bare DocumentRequestItem, then read
     * ->documentRequest->firm_id off it", since that lazy relation load
     * is itself a fresh query against the already-FORCE-protected
     * document_requests table (Checkpoint 10) with no context
     * necessarily active at that point (definition() always runs, even
     * when a state() override like forItem() below will replace its
     * output entirely — Laravel does not skip it). Building top-down
     * avoids that read-after-context-cleared trap entirely.
     */
    public function definition(): array
    {
        $client = Client::factory()->create();
        $request = DocumentRequest::factory()->create(['firm_id' => $client->firm_id, 'client_id' => $client->id]);
        $item = DocumentRequestItem::factory()->create(['document_request_id' => $request->id]);

        return [
            'firm_id' => $request->firm_id,
            'document_request_item_id' => $item->id,
            'document_chase_rule_id' => null,
            'event_type' => 'reminder_queued',
            'metadata_json' => [],
            'actor_user_id' => null,
        ];
    }

    /**
     * Takes $firm explicitly rather than deriving it by reading
     * $item->documentRequest->firm_id — document_request_items carries
     * no firm_id of its own, so that relation load would be a fresh
     * query against the already-FORCE-protected document_requests
     * table (Checkpoint 10), and definition() above ALWAYS runs first
     * (even though state() will override every field it returns),
     * itself calling real factories with real context side effects
     * that would clobber whatever ambient context this closure might
     * otherwise have relied on. Every current caller already has the
     * item's real owning Firm in hand (it created the item itself), so
     * requiring it explicitly here removes the DB read — and the
     * context-timing hazard — entirely, matching the robust pattern
     * every other FORCE-RLS factory's forXxx() helper already uses
     * (e.g. MatterReadinessScoreFactory::forMatter(), which reads
     * $matter->firm_id — a plain in-memory property, not a relation
     * load — for the same reason).
     */
    public function forItem(DocumentRequestItem $item, Firm $firm, ?DocumentChaseRule $rule = null): static
    {
        return $this->state(fn () => [
            'firm_id' => $firm->id,
            'document_request_item_id' => $item->id,
            'document_chase_rule_id' => $rule?->id,
        ]);
    }
}
