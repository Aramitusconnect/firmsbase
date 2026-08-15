<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Models\PracticeArea;
use App\Services\Configuration\PracticeAreaCanonicalizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Proves the practice-area taxonomy data-quality layer behaves exactly
 * as mission sections 28–30 require: the four separator/case variants
 * of one concept are detected as POTENTIALLY EQUIVALENT with stated
 * evidence, genuinely distinct taxonomy is NOT collided, and ambiguous
 * aliases are surfaced.
 *
 * IMPORTANT — this suite runs against a NON-EMPTY catalog. Two
 * migrations seed `practice_areas` (2026_08_08_100011's 21 snake_case
 * internal rows and 2026_11_10_100011's 15 kebab-case marketplace
 * rows), so every fixture below uses a deliberately synthetic
 * "zzz*"-prefixed taxonomy that cannot collide with real seeded data,
 * and catalog-wide assertions filter to those fixtures. Asserting
 * against absolute catalog-wide counts would make this suite a
 * tripwire for unrelated seed changes rather than a test of this
 * service.
 */
class PracticeAreaCanonicalizationServiceTest extends TestCase
{
    use RefreshDatabase;

    private PracticeAreaCanonicalizationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PracticeAreaCanonicalizationService::class);
    }

    /**
     * Mission section 28's explicit test case.
     */
    #[DataProvider('equivalentSpellings')]
    public function test_section_28_separator_and_case_variants_all_normalize_together(string $spelling): void
    {
        $this->assertSame('civil litigation', $this->service->normalizeIdentifier($spelling));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function equivalentSpellings(): array
    {
        return [
            'underscore' => ['civil_litigation'],
            'hyphen' => ['civil-litigation'],
            'title case with space' => ['Civil Litigation'],
            'lowercase with space' => ['civil litigation'],
            'doubled internal whitespace' => ['civil  litigation'],
            'leading and trailing whitespace' => ['  Civil-Litigation  '],
            'mixed separators' => ['Civil_ -Litigation'],
        ];
    }

    public function test_blank_and_null_identifiers_normalize_to_null(): void
    {
        $this->assertNull($this->service->normalizeIdentifier(null));
        $this->assertNull($this->service->normalizeIdentifier('   '));
        $this->assertNull($this->service->normalizeIdentifier('-_-'));
    }

    public function test_detects_a_separator_variant_of_an_existing_code_as_a_duplicate_candidate(): void
    {
        $existing = PracticeArea::factory()->create([
            'code' => 'zzz_maritime_salvage',
            'name' => 'Zzz Maritime Salvage',
        ]);

        $candidates = $this->service->duplicateCandidatesFor(
            name: 'Zzz Maritime Salvage',
            code: 'zzz-maritime-salvage',
        );

        $this->assertCount(1, $candidates);
        $this->assertSame($existing->id, $candidates->first()->practiceArea->id);
        $this->assertNotEmpty($candidates->first()->matchReasons);
    }

    public function test_duplicate_candidate_reports_which_field_actually_matched(): void
    {
        PracticeArea::factory()->create([
            'code' => 'zzz_maritime_salvage',
            'name' => 'Zzz Maritime Salvage',
        ]);

        $reasons = $this->service
            ->duplicateCandidatesFor(name: 'zzz-maritime-salvage', code: 'zzz-unrelated-code')
            ->first()
            ->matchReasons;

        // The evidence must name what collided, never just assert
        // "duplicate" — mission section 28 requires showing the operator
        // the matching value.
        $this->assertNotEmpty(array_filter(
            $reasons,
            fn (string $reason): bool => str_contains($reason, 'zzz maritime salvage'),
        ));
    }

    public function test_similar_but_distinct_names_are_not_treated_as_duplicates(): void
    {
        PracticeArea::factory()->create([
            'code' => 'zzz_business_law',
            'name' => 'Zzz Business Law',
        ]);

        // Mission section 29: these may or may not be equivalent, and a
        // conservative normalizer must NOT decide that for the operator.
        $candidates = $this->service->duplicateCandidatesFor(
            name: 'Zzz Business / Corporate Law',
            code: 'zzz_business_corporate_law',
        );

        $this->assertCount(0, $candidates);
    }

    public function test_excluding_id_prevents_a_record_matching_itself_on_edit(): void
    {
        $existing = PracticeArea::factory()->create([
            'code' => 'zzz_admiralty',
            'name' => 'Zzz Admiralty',
        ]);

        $this->assertCount(
            0,
            $this->service->duplicateCandidatesFor(
                name: 'Zzz Admiralty',
                code: 'zzz_admiralty',
                excludingId: $existing->id,
            ),
        );
    }

    public function test_catalog_wide_scan_reports_each_suspected_pair_once(): void
    {
        $left = PracticeArea::factory()->create(['code' => 'zzz_space_law', 'name' => 'Zzz Space Law']);
        $right = PracticeArea::factory()->create(['code' => 'zzz-space-law', 'name' => 'Zzz space law']);

        $pairsForFixture = $this->service->suspectedDuplicatePairs()->filter(
            fn (array $pair): bool => in_array($left->id, [$pair['lower']->id, $pair['higher']->id], true)
                && in_array($right->id, [$pair['lower']->id, $pair['higher']->id], true),
        );

        $this->assertCount(1, $pairsForFixture);
    }

    /**
     * The seeded catalog itself contains real separator-variant
     * duplicates (e.g. `civil_litigation` #5 and `civil-litigation`
     * #31, both named "Civil Litigation"). The scan must find them —
     * this is the mission's actual data-quality subject, not a
     * hypothetical.
     */
    public function test_scan_detects_the_real_seeded_separator_variant_duplicates(): void
    {
        $pairs = $this->service->suspectedDuplicatePairs();

        $codePairs = $pairs
            ->map(fn (array $pair): array => [$pair['lower']->code, $pair['higher']->code])
            ->map(function (array $codes): string {
                sort($codes);

                return implode('|', $codes);
            })
            ->all();

        $this->assertContains('civil-litigation|civil_litigation', $codePairs);
        $this->assertContains('personal-injury|personal_injury', $codePairs);
    }

    /**
     * The counterpart guarantee: the seeded catalog's genuinely
     * DIFFERENT names must not be collided. "Business / Corporate Law"
     * (#4) and "Business Law" (#28) is mission section 29's own
     * example of a pair a normalizer must leave to human judgement.
     */
    public function test_scan_does_not_collide_the_seeded_business_law_variants(): void
    {
        $pairs = $this->service->suspectedDuplicatePairs()->filter(
            fn (array $pair): bool => in_array($pair['lower']->code, ['business_corporate_law', 'business-law'], true)
                && in_array($pair['higher']->code, ['business_corporate_law', 'business-law'], true),
        );

        $this->assertCount(0, $pairs);
    }

    public function test_stored_aliases_participate_in_duplicate_detection(): void
    {
        $existing = PracticeArea::factory()->create([
            'code' => 'zzz_personal_injury',
            'name' => 'Zzz Personal Injury',
            'synonyms' => ['Zzz Injury Law', 'zzz-tort-claims'],
        ]);

        $candidates = $this->service->duplicateCandidatesFor(name: 'Zzz Tort Claims');

        $this->assertCount(1, $candidates);
        $this->assertSame($existing->id, $candidates->first()->practiceArea->id);
    }

    public function test_an_alias_claimed_by_two_practice_areas_is_reported_as_ambiguous(): void
    {
        PracticeArea::factory()->create([
            'code' => 'zzz_personal_injury',
            'name' => 'Zzz Personal Injury',
            'synonyms' => ['zzz-accident-claims'],
        ]);
        PracticeArea::factory()->create([
            'code' => 'zzz_motor_vehicle',
            'name' => 'Zzz Motor Vehicle',
            'synonyms' => ['Zzz Accident Claims'],
        ]);

        $ambiguous = $this->service->ambiguousAliases()
            ->firstWhere('normalized', 'zzz accident claims');

        $this->assertNotNull($ambiguous);
        $this->assertCount(2, $ambiguous['practiceAreas']);
    }

    public function test_an_alias_colliding_with_another_practice_areas_canonical_identity_is_ambiguous(): void
    {
        PracticeArea::factory()->create([
            'code' => 'zzz_family_law',
            'name' => 'Zzz Family Law',
            'synonyms' => ['zzz-immigration'],
        ]);
        PracticeArea::factory()->create([
            'code' => 'zzz_immigration',
            'name' => 'Zzz Immigration',
        ]);

        $ambiguous = $this->service->ambiguousAliases()
            ->firstWhere('normalized', 'zzz immigration');

        $this->assertNotNull($ambiguous);
        $this->assertCount(2, $ambiguous['practiceAreas']);
    }

    public function test_unambiguous_aliases_are_not_reported(): void
    {
        PracticeArea::factory()->create([
            'code' => 'zzz_personal_injury',
            'name' => 'Zzz Personal Injury',
            'synonyms' => ['Zzz Injury Law'],
        ]);
        PracticeArea::factory()->create([
            'code' => 'zzz_immigration',
            'name' => 'Zzz Immigration',
            'synonyms' => ['Zzz Visa Law'],
        ]);

        $normalizedAmbiguous = $this->service->ambiguousAliases()->pluck('normalized')->all();

        $this->assertNotContains('zzz injury law', $normalizedAmbiguous);
        $this->assertNotContains('zzz visa law', $normalizedAmbiguous);
    }

    public function test_non_string_and_blank_synonym_entries_are_ignored_rather_than_crashing(): void
    {
        $practiceArea = PracticeArea::factory()->create([
            'code' => 'zzz_immigration',
            'name' => 'Zzz Immigration',
            'synonyms' => ['Zzz Visa Law', '', '   ', 42, null],
        ]);

        $this->assertSame(['Zzz Visa Law'], $this->service->aliasesOf($practiceArea));
    }

    public function test_a_practice_area_with_no_synonyms_value_reports_no_aliases(): void
    {
        $practiceArea = PracticeArea::factory()->create(['synonyms' => null]);

        $this->assertSame([], $this->service->aliasesOf($practiceArea));
    }

    public function test_normalized_forms_cover_name_code_slug_and_aliases(): void
    {
        $practiceArea = PracticeArea::factory()->create([
            'code' => 'zzz_maritime_salvage',
            'slug' => 'zzz-maritime-salvage',
            'name' => 'Zzz Maritime Salvage',
            'synonyms' => ['Zzz Wreck Recovery'],
        ]);

        $forms = $this->service->normalizedFormsOf($practiceArea);

        // name/code/slug all reduce to the same string, so the distinct
        // set is that one form plus the alias.
        $this->assertContains('zzz maritime salvage', $forms);
        $this->assertContains('zzz wreck recovery', $forms);
        $this->assertCount(2, $forms);
    }
}
