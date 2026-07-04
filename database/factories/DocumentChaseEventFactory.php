<?php

namespace Database\Factories;

use App\Models\DocumentChaseRule;
use App\Models\DocumentRequestItem;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\DocumentChaseEvent>
 */
class DocumentChaseEventFactory extends Factory
{
    protected $model = \App\Models\DocumentChaseEvent::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'document_request_item_id' => DocumentRequestItem::factory(),
            'document_chase_rule_id' => null,
            'event_type' => 'reminder_queued',
            'metadata_json' => [],
            'actor_user_id' => null,
        ];
    }

    public function forItem(DocumentRequestItem $item, ?DocumentChaseRule $rule = null): static
    {
        return $this->state(fn () => [
            'firm_id' => $item->documentRequest->firm_id,
            'document_request_item_id' => $item->id,
            'document_chase_rule_id' => $rule?->id,
        ]);
    }
}
