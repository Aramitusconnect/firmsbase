<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * HasPublicUuid — auto-generates an immutable, public-facing UUIDv7 value.
 *
 * Dual-ID design:
 *   - `id`   bigint, auto-increment — the real primary key, used for every
 *            foreign key relationship. Never exposed externally.
 *   - `uuid` unique, immutable — the only identifier meant for public/API
 *            exposure. NOT the primary key.
 *
 * Laravel 13.x ships `Illuminate\Support\Str::uuid7(?DateTimeInterface $time = null)`
 * natively (confirmed against the framework's own 13.x source). No
 * third-party package or custom generator is needed.
 *
 * This trait deliberately does NOT use Eloquent's built-in `HasUuids`
 * trait. `HasUuids` assumes the UUID *is* the primary key (overrides
 * getKeyType()/getIncrementing()), which conflicts with the dual-ID
 * design here, and its default `newUniqueId()` calls
 * `Str::orderedUuid()`, which has had version-ambiguity issues reported
 * against it historically. Calling `Str::uuid7()` directly avoids that.
 *
 * Any model applying this trait MUST have both an `id` bigint primary
 * key and a `uuid` column — this is the exact mechanism that failed
 * silently for `users` in the previous rebuild attempt: the migration
 * added the `uuid` column, but the model was never given this trait,
 * so nothing ever populated it. Applying this trait is the ONLY thing
 * that populates `uuid` — there is no database default, no factory
 * default, no separate seeder step.
 */
trait HasPublicUuid
{
    protected static function bootHasPublicUuid(): void
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid7();
            }
        });

        static::saving(function ($model) {
            if ($model->exists && $model->isDirty('uuid')) {
                throw new \LogicException(
                    'uuid is immutable and cannot be changed after creation.'
                );
            }
        });
    }

    /**
     * Find a model by its public UUID. Throws if not found.
     */
    public static function findByUuid(string $uuid): static
    {
        return static::where('uuid', $uuid)->firstOrFail();
    }

    /**
     * Route-model-binding key — resolves {model} route parameters by uuid,
     * not by the internal bigint id, so the id is never exposed via URLs.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
