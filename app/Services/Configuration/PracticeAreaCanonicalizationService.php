<?php

declare(strict_types=1);

namespace App\Services\Configuration;

use App\Models\PracticeArea;
use App\ValueObjects\Configuration\PracticeAreaDuplicateCandidate;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * PracticeAreaCanonicalizationService — read-only taxonomy data-quality
 * analysis over the GLOBAL `practice_areas` catalog. Detects, explains,
 * and reports; it NEVER mutates a row. Every write to `practice_areas`
 * still goes through the pre-existing canonical PracticeAreaService —
 * this class deliberately adds no second write path.
 *
 * WHY A LOCAL NORMALIZER RATHER THAN REUSING AN EXISTING ONE:
 * MarketplaceImportDuplicateDetectionService::normalizeNameForMatching()
 * is (a) private and (b) tuned for FIRM NAMES — it strips periods and
 * commas ("P.L.L.C." → "pllc") but deliberately leaves hyphens and
 * underscores alone, because those carry meaning in a business name.
 * Practice-area identity is the opposite problem: `code`/`slug` are
 * machine identifiers where `civil_litigation`, `civil-litigation`,
 * `Civil Litigation` and `civil  litigation` are the SAME concept
 * spelled four ways (mission section 28's explicit test case), and that
 * is exactly the equivalence firm-name matching must never make. The
 * two normalizers therefore cannot be merged without breaking one of
 * them; this is a deliberate, documented non-reuse, not duplication.
 *
 * SUSPECTED, NEVER CONFIRMED: normalization proves two STRINGS collide.
 * It cannot prove two practice areas are the same legal concept —
 * mission section 29's "Business Law" vs "Business / Corporate Law"
 * case is precisely a pair that may or may not be equivalent depending
 * on how each is actually used. Every method here therefore returns
 * SUSPECTED candidates plus evidence, and confirmation remains a human
 * judgement gated by section 36's owner approval.
 */
class PracticeAreaCanonicalizationService
{
    /**
     * Columns compared against, in the order their evidence is
     * reported. Keyed by column, valued by operator-facing label.
     */
    private const COMPARED_COLUMNS = [
        'name' => 'Name',
        'code' => 'Canonical code',
        'slug' => 'Public slug',
    ];

    /**
     * Deterministic identifier normalization. Case-folds, trims, and
     * treats hyphen/underscore/any whitespace run as ONE canonical
     * separator, so all four of mission section 28's required
     * equivalents reduce to the same string:
     *
     *   "civil_litigation"  → "civil litigation"
     *   "civil-litigation"  → "civil litigation"
     *   "Civil Litigation"  → "civil litigation"
     *   "civil  litigation" → "civil litigation"
     *
     * Deliberately conservative beyond that: it does NOT strip words,
     * map abbreviations, stem, or apply fuzzy/edit-distance matching.
     * Section 29 is explicit that fuzzy text similarity must never be
     * the final truth, and a looser normalizer would collide genuinely
     * distinct taxonomy ("Business Law" vs "Business / Corporate Law")
     * while adding no real precision.
     */
    public function normalizeIdentifier(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = Str::lower(trim($value));
        $normalized = preg_replace('/[\s_\-]+/u', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Every distinct normalized form a single practice area answers to:
     * its name, code, slug, and each stored alias.
     *
     * @return list<string>
     */
    public function normalizedFormsOf(PracticeArea $practiceArea): array
    {
        $forms = [
            $this->normalizeIdentifier($practiceArea->name),
            $this->normalizeIdentifier($practiceArea->code),
            $this->normalizeIdentifier($practiceArea->slug),
        ];

        foreach ($this->aliasesOf($practiceArea) as $alias) {
            $forms[] = $this->normalizeIdentifier($alias);
        }

        return array_values(array_unique(array_filter($forms, fn (?string $f): bool => $f !== null)));
    }

    /**
     * Stored aliases for a practice area. `practice_areas.synonyms` is
     * a real, existing JSON column — but NOTHING in this codebase
     * currently consults it for resolution (MarketplaceSearchService's
     * own docblock states synonym matching "exists in the schema but is
     * not yet consulted here"). This accessor therefore reads stored
     * alias DATA only; it must never be presented as proof that aliases
     * RESOLVE anywhere (mission section 100: no fake capability).
     *
     * @return list<string>
     */
    public function aliasesOf(PracticeArea $practiceArea): array
    {
        $synonyms = $practiceArea->synonyms;

        if (! is_array($synonyms)) {
            return [];
        }

        $aliases = [];

        foreach ($synonyms as $synonym) {
            if (is_string($synonym) && trim($synonym) !== '') {
                $aliases[] = trim($synonym);
            }
        }

        return array_values(array_unique($aliases));
    }

    /**
     * Existing practice areas whose name/code/slug/alias normalizes
     * onto any of the supplied candidate values. Used by the Create and
     * Edit actions to warn BEFORE a write (section 28), and by the
     * catalog-wide scan below.
     *
     * @param  list<string>  $aliases
     * @return Collection<int, PracticeAreaDuplicateCandidate>
     */
    public function duplicateCandidatesFor(
        ?string $name,
        ?string $code = null,
        ?string $slug = null,
        array $aliases = [],
        ?int $excludingId = null,
    ): Collection {
        $wanted = [
            'name' => $this->normalizeIdentifier($name),
            'code' => $this->normalizeIdentifier($code),
            'slug' => $this->normalizeIdentifier($slug),
        ];

        $wantedAliases = array_values(array_filter(
            array_map(fn (string $a): ?string => $this->normalizeIdentifier($a), $aliases),
            fn (?string $a): bool => $a !== null,
        ));

        $allWanted = array_values(array_unique(array_filter(
            array_merge(array_values($wanted), $wantedAliases),
            fn (?string $v): bool => $v !== null,
        )));

        if ($allWanted === []) {
            return collect();
        }

        return $this->catalog($excludingId)
            ->map(function (PracticeArea $existing) use ($wanted, $wantedAliases): ?PracticeAreaDuplicateCandidate {
                $reasons = $this->matchReasons($existing, $wanted, $wantedAliases);

                return $reasons === [] ? null : new PracticeAreaDuplicateCandidate($existing, $reasons);
            })
            ->filter()
            ->values();
    }

    /**
     * Catalog-wide scan for pairs that normalize onto each other.
     * Each unordered pair is reported once. Powers the Configuration
     * Overview's "Suspected Duplicates" metric and its drill-down.
     *
     * @return Collection<int, array{lower: PracticeArea, higher: PracticeArea, reasons: list<string>}>
     */
    public function suspectedDuplicatePairs(): Collection
    {
        $catalog = $this->catalog()->values();
        $pairs = collect();

        foreach ($catalog as $i => $left) {
            foreach ($catalog->slice($i + 1) as $right) {
                $reasons = $this->matchReasons(
                    $right,
                    [
                        'name' => $this->normalizeIdentifier($left->name),
                        'code' => $this->normalizeIdentifier($left->code),
                        'slug' => $this->normalizeIdentifier($left->slug),
                    ],
                    array_map(
                        fn (string $a): string => (string) $this->normalizeIdentifier($a),
                        $this->aliasesOf($left),
                    ),
                );

                if ($reasons !== []) {
                    $pairs->push(['lower' => $left, 'higher' => $right, 'reasons' => $reasons]);
                }
            }
        }

        return $pairs->values();
    }

    /**
     * Aliases that are AMBIGUOUS — one normalized alias claimed by two
     * or more practice areas, or an alias that collides with a
     * different practice area's canonical name/code/slug.
     *
     * This is a genuine data-quality signal today even though no
     * resolver consults `synonyms` yet: mission section 30's invariant
     * is that "one alias must not ambiguously map to multiple canonical
     * areas", and an ambiguity already stored in the catalog is exactly
     * what would make a future alias resolver non-deterministic. Fixing
     * it is cheap now and load-bearing later.
     *
     * @return Collection<int, array{alias: string, normalized: string, practiceAreas: Collection<int, PracticeArea>}>
     */
    public function ambiguousAliases(): Collection
    {
        $catalog = $this->catalog();

        /** @var array<string, array{alias: string, ids: array<int, PracticeArea>}> $claims */
        $claims = [];

        foreach ($catalog as $practiceArea) {
            foreach ($this->aliasesOf($practiceArea) as $alias) {
                $normalized = $this->normalizeIdentifier($alias);

                if ($normalized === null) {
                    continue;
                }

                $claims[$normalized] ??= ['alias' => $alias, 'ids' => []];
                $claims[$normalized]['ids'][$practiceArea->id] = $practiceArea;
            }
        }

        $ambiguous = collect();

        foreach ($claims as $normalized => $claim) {
            $claimants = collect($claim['ids']);

            // Also treat "alias of A collides with canonical identity of B"
            // as ambiguous — a resolver could not choose between them.
            foreach ($catalog as $practiceArea) {
                if ($claimants->has($practiceArea->id)) {
                    continue;
                }

                $canonicalForms = array_filter([
                    $this->normalizeIdentifier($practiceArea->name),
                    $this->normalizeIdentifier($practiceArea->code),
                    $this->normalizeIdentifier($practiceArea->slug),
                ]);

                if (in_array($normalized, $canonicalForms, true)) {
                    $claimants->put($practiceArea->id, $practiceArea);
                }
            }

            if ($claimants->count() > 1) {
                $ambiguous->push([
                    'alias' => $claim['alias'],
                    'normalized' => $normalized,
                    'practiceAreas' => $claimants->values(),
                ]);
            }
        }

        return $ambiguous->values();
    }

    /**
     * @param  array{name: ?string, code: ?string, slug: ?string}  $wanted
     * @param  list<string>  $wantedAliases
     * @return list<string>
     */
    private function matchReasons(PracticeArea $existing, array $wanted, array $wantedAliases): array
    {
        $reasons = [];

        foreach (self::COMPARED_COLUMNS as $column => $label) {
            $existingValue = $this->normalizeIdentifier($existing->{$column});

            if ($existingValue === null) {
                continue;
            }

            foreach (self::COMPARED_COLUMNS as $wantedColumn => $wantedLabel) {
                if (($wanted[$wantedColumn] ?? null) !== $existingValue) {
                    continue;
                }

                $reasons[] = $column === $wantedColumn
                    ? sprintf('%s normalizes to "%s"', $label, $existingValue)
                    : sprintf('%s normalizes to existing %s "%s"', $wantedLabel, Str::lower($label), $existingValue);
            }

            if (in_array($existingValue, $wantedAliases, true)) {
                $reasons[] = sprintf('Proposed alias normalizes to existing %s "%s"', Str::lower($label), $existingValue);
            }
        }

        foreach ($this->aliasesOf($existing) as $existingAlias) {
            $normalizedAlias = $this->normalizeIdentifier($existingAlias);

            if ($normalizedAlias === null) {
                continue;
            }

            foreach (self::COMPARED_COLUMNS as $wantedColumn => $wantedLabel) {
                if (($wanted[$wantedColumn] ?? null) === $normalizedAlias) {
                    $reasons[] = sprintf('%s normalizes to existing alias "%s"', $wantedLabel, $existingAlias);
                }
            }

            if (in_array($normalizedAlias, $wantedAliases, true)) {
                $reasons[] = sprintf('Proposed alias normalizes to existing alias "%s"', $existingAlias);
            }
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @return Collection<int, PracticeArea>
     */
    private function catalog(?int $excludingId = null): Collection
    {
        return PracticeArea::query()
            ->when($excludingId !== null, fn ($q) => $q->whereKeyNot($excludingId))
            ->orderBy('id')
            ->get(['id', 'code', 'slug', 'name', 'is_active', 'is_marketplace_visible', 'synonyms', 'created_at', 'updated_at']);
    }
}
