<?php

declare(strict_types=1);

namespace App\Integrations\Data;

use App\Integrations\Enums\ResourceType;

/**
 * SanitizedPayloadReference — the ONLY type any Checkpoint 6 outbox/
 * sync write path accepts for payload-shaped data (frozen-design-post-
 * review.md §11; agent-6g-privacy-payload-retention-audit.md §1). No
 * method signature anywhere in this checkpoint's write path accepts a
 * raw Eloquent `Model` — this is what makes `$model->toArray()`/
 * `json_encode($model)` reaching a payload column structurally, not
 * merely conventionally, unreachable.
 *
 * Built exclusively by IntegrationOutboxPayloadBuilderService's
 * per-ResourceType allowlist methods, mirroring
 * App\Services\WebhookPayloadBuilderService's proven shape exactly: a
 * `(resource_type, resource_id, small allowlisted field map)` triple,
 * never a hydrated relationship graph or a wider structure.
 *
 * `resourceId` is the resource's own IMMUTABLE identifier (its uuid
 * where the model has one — matching WebhookPayloadBuilderService's
 * own convention of anchoring on uuid, never the bare autoincrement
 * id), stored as a string so it can carry a uuid, not just a bigint.
 *
 * `fields` is a small, explicitly-named, allowlisted map — never the
 * result of `->toArray()`/`->toJson()` on any model.
 */
final class SanitizedPayloadReference
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly ResourceType $resourceType,
        public readonly string $resourceId,
        public readonly array $fields = [],
    ) {}

    /**
     * @return array{resource_type: string, resource_id: string, fields: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'resource_type' => $this->resourceType->value,
            'resource_id' => $this->resourceId,
            'fields' => $this->fields,
        ];
    }

    /**
     * sha256 over the canonical (key-sorted) JSON of this already-
     * sanitized reference — never over raw model state. Used for
     * change-detection/idempotency only, never as the sole uniqueness
     * key (frozen-design-post-review.md §11).
     */
    public function hash(): string
    {
        $canonical = $this->toArray();
        ksort($canonical['fields']);

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }
}
