<?php

declare(strict_types=1);

namespace App\Integrations\Data;

use InvalidArgumentException;

/**
 * SanitizedUsageMetadataReference — the ONLY type
 * `IntegrationUsageRecorderService::recordOnce()` accepts for
 * `integration_usage_records.metadata_json` (Checkpoint 9, frozen
 * design §2). Mirrors `App\Integrations\Data\SanitizedPayloadReference`'s
 * shape and discipline: a small, explicitly-named, allowlisted map —
 * never the result of `$model->toArray()`/`$model->toJson()`, never a
 * raw provider response body, never `$request->all()`. No method
 * signature anywhere in this checkpoint's usage-recording write path
 * accepts a raw Eloquent `Model`.
 *
 * Unlike `SanitizedPayloadReference`, this DTO carries no
 * `resourceType`/`resourceId` pair of its own — `integration_usage_records`
 * already has first-class `resource_type` and other identifying columns
 * at the top level (frozen schema §2), so duplicating a resource
 * pointer inside the metadata blob would be redundant. This DTO exists
 * purely to gate the small amount of EXTRA, operation-specific context
 * (e.g. a byte count, a page count, a rate-limit bucket name) that
 * doesn't warrant its own dedicated column.
 *
 * Constructor-time validation (mirrors
 * `App\Integrations\Data\SanitizedHealthDiagnostic`'s constructor-
 * validated discipline, applied to shape rather than a closed
 * vocabulary): every value must be a scalar, null, or an array of
 * scalars/null — never an object, a resource, or an Eloquent Model —
 * so this DTO cannot silently become a vehicle for smuggling a hydrated
 * model or a raw provider payload into storage.
 */
final class SanitizedUsageMetadataReference
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields = [],
    ) {
        $this->assertOnlyScalarShaped($this->fields);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->fields;
    }

    /**
     * sha256 over the canonical (key-sorted) JSON of this
     * already-sanitized reference — never over raw model state. Used
     * for change-detection only, never as the sole uniqueness key
     * (mirrors SanitizedPayloadReference::hash()'s identical
     * discipline).
     */
    public function hash(): string
    {
        $canonical = $this->fields;
        ksort($canonical);

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<mixed, mixed>  $values
     */
    private function assertOnlyScalarShaped(array $values): void
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $this->assertOnlyScalarShaped($value);

                continue;
            }

            if ($value !== null && ! is_scalar($value)) {
                throw new InvalidArgumentException(
                    "SanitizedUsageMetadataReference field \"{$key}\" must be a scalar, null, or an array of ".
                    'scalars/null — objects, resources, and Eloquent Models are never permitted.'
                );
            }
        }
    }
}
