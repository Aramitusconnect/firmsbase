<?php

declare(strict_types=1);

namespace App\Marketplace\Models\Concerns;

use Illuminate\Support\Str;

/**
 * HasMarketplaceSlug — Mission 2 (MyAttorney Marketplace Core). No slug
 * mechanism exists anywhere else in this codebase (confirmed by
 * repository audit) — every other human-readable route key
 * (PracticeArea, LeadSource) uses a plain unique `code` string set by
 * the caller, never a generated slug. Public marketplace URLs need
 * real generated slugs (section 43: human-readable, not the internal
 * bigint id or the public uuid), so this is a new, small, standalone
 * concern rather than an adaptation of an existing one.
 *
 * Collision handling (section 44): "Smith Law" / "Smith Law" become
 * `smith-law` / `smith-law-2` — never exposing the internal integer id
 * to solve the collision, and never silently overwriting the first
 * slug when a second colliding name arrives.
 *
 * The model applying this trait must define `slugSourceAttribute():
 * string` (returning the name of the attribute the slug is derived
 * from) and `slugSourceValue(): ?string` is provided by that
 * attribute directly.
 */
trait HasMarketplaceSlug
{
    public static function generateUniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = static::slugify($source);
        $slug = $base;
        $suffix = 2;

        while (static::slugExists($slug, $ignoreId)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    public static function slugify(string $value): string
    {
        $slug = Str::slug($value);

        return $slug !== '' ? $slug : 'listing';
    }

    private static function slugExists(string $slug, ?int $ignoreId): bool
    {
        return static::query()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }
}
