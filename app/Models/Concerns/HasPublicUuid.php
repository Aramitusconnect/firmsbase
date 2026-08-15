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

    /**
     * Reject a malformed route key before it reaches PostgreSQL.
     *
     * Because getRouteKeyName() above binds every one of these models by
     * `uuid`, and `uuid` is a real PostgreSQL `uuid` column, the framework
     * default (`where uuid = <raw route value>`) hands whatever the caller
     * typed straight to the database. A value that is not a well-formed
     * UUID — `/clients/3`, a truncated id, a pasted fragment — makes
     * PostgreSQL raise SQLSTATE[22P02] `invalid input syntax for type uuid`,
     * which surfaces as a 500 with a QueryException. Observed on staging
     * against the real deployed release, not hypothesised.
     *
     * That was never a tenant-data exposure — the generated SQL is still
     * firm-scoped and returns nothing — but a malformed public identifier
     * must not be able to reach the database as an invalid value or to turn
     * a routine "no such record" into a server error. It also kept
     * malformed input distinguishable from a valid-but-absent id (500 vs
     * 404), which is a small existence-oracle difference worth removing.
     *
     * The guard adds a constraint and never removes one: the query passed in
     * already carries the caller's tenant scoping and any global scopes, and
     * is returned untouched for well-formed values. A non-UUID resolves to a
     * query that matches nothing, so binding misses and the framework raises
     * its normal 404 — the same answer an unknown-but-valid UUID gets.
     *
     * Deliberately NOT done: catching QueryException/SQLSTATE and mapping it
     * to 404 (that would hide genuine database faults), casting the column
     * to text in SQL (defeats the uuid index and still accepts nonsense), or
     * routing by the bigint `id` instead (re-exposes the internal key this
     * trait exists to hide).
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $field ??= $this->getRouteKeyName();

        // Only guard the uuid column. An explicit `{model:other_field}`
        // binding is somebody else's contract and keeps stock behaviour.
        if ($field === 'uuid' && ! Str::isUuid($value)) {
            // whereIn(..., []) compiles to `0 = 1` — no raw SQL, no row.
            return $query->whereIn($this->getQualifiedKeyName(), []);
        }

        return parent::resolveRouteBindingQuery($query, $value, $field);
    }
}
