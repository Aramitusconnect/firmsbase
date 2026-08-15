<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Models\MatterType;
use App\Models\PlatformAdmin;
use App\Models\PracticeArea;
use App\Models\SecurityEvent;
use App\Services\PracticeAreaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Proves the duplicate and canonical-code guardrails live in the
 * CANONICAL SERVICE, not merely in the Filament form — mission section
 * 79 ("all mutation authorization is server-side; hidden button ≠
 * authorization"). Every test here calls PracticeAreaService directly,
 * bypassing the UI entirely, and still gets stopped.
 */
class PracticeAreaGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private PracticeAreaService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PracticeAreaService::class);
    }

    public function test_creating_a_separator_variant_of_an_existing_practice_area_is_refused(): void
    {
        PracticeArea::factory()->create(['code' => 'zzz_civil_litigation', 'name' => 'Zzz Civil Litigation']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/may be a duplicate/i');

        $this->service->create(['code' => 'zzz-civil-litigation', 'name' => 'Zzz Civil Litigation']);
    }

    public function test_the_refusal_message_names_the_colliding_record_as_evidence(): void
    {
        PracticeArea::factory()->create(['code' => 'zzz_civil_litigation', 'name' => 'Zzz Civil Litigation']);

        try {
            $this->service->create(['code' => 'zzz-civil-litigation', 'name' => 'Zzz Civil Litigation']);
            $this->fail('expected the duplicate to be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('zzz_civil_litigation', $e->getMessage());
            $this->assertStringContainsString('Zzz Civil Litigation', $e->getMessage());
        }
    }

    public function test_no_row_is_written_when_a_duplicate_is_refused(): void
    {
        PracticeArea::factory()->create(['code' => 'zzz_civil_litigation', 'name' => 'Zzz Civil Litigation']);
        $before = PracticeArea::query()->count();

        try {
            $this->service->create(['code' => 'zzz-civil-litigation', 'name' => 'Zzz Civil Litigation']);
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame($before, PracticeArea::query()->count());
    }

    public function test_a_deliberate_override_reason_allows_genuinely_distinct_taxonomy_through(): void
    {
        PracticeArea::factory()->create(['code' => 'zzz_civil_litigation', 'name' => 'Zzz Civil Litigation']);

        $created = $this->service->create(
            ['code' => 'zzz-civil-litigation', 'name' => 'Zzz Civil Litigation'],
            duplicateOverrideReason: 'State-court variant tracked separately for reporting.',
        );

        $this->assertTrue($created->exists);
        $this->assertSame('zzz-civil-litigation', $created->code);
    }

    public function test_a_blank_override_reason_does_not_count_as_acknowledgement(): void
    {
        PracticeArea::factory()->create(['code' => 'zzz_civil_litigation', 'name' => 'Zzz Civil Litigation']);

        $this->expectException(InvalidArgumentException::class);

        $this->service->create(
            ['code' => 'zzz-civil-litigation', 'name' => 'Zzz Civil Litigation'],
            duplicateOverrideReason: '   ',
        );
    }

    public function test_an_overridden_duplicate_creation_is_audited_with_its_reason(): void
    {
        $actor = PlatformAdmin::factory()->create(['is_active' => true]);
        PracticeArea::factory()->create(['code' => 'zzz_civil_litigation', 'name' => 'Zzz Civil Litigation']);

        $this->service->create(
            ['code' => 'zzz-civil-litigation', 'name' => 'Zzz Civil Litigation'],
            $actor,
            duplicateOverrideReason: 'Deliberately distinct — appellate only.',
        );

        $event = SecurityEvent::query()->where('event_type', 'practice_area_created')->latest('id')->first();

        $this->assertNotNull($event);
        $this->assertSame('Deliberately distinct — appellate only.', $event->metadata['duplicate_override_reason'] ?? null);
        $this->assertNotEmpty($event->metadata['duplicate_candidate_ids'] ?? []);
    }

    public function test_an_ordinary_non_duplicate_creation_records_no_override_metadata(): void
    {
        $actor = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->service->create(['code' => 'zzz_unique_area', 'name' => 'Zzz Unique Area'], $actor);

        $event = SecurityEvent::query()->where('event_type', 'practice_area_created')->latest('id')->first();

        $this->assertArrayNotHasKey('duplicate_override_reason', $event->metadata);
        $this->assertArrayNotHasKey('duplicate_candidate_ids', $event->metadata);
    }

    public function test_byte_identical_code_is_still_refused_by_the_pre_existing_unique_guard(): void
    {
        PracticeArea::factory()->create(['code' => 'zzz_taken']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already exists/i');

        // An override reason must NOT be able to defeat the real
        // uniqueness constraint — mission section 28.
        $this->service->create(
            ['code' => 'zzz_taken', 'name' => 'Zzz Something Else'],
            duplicateOverrideReason: 'trying to force it through',
        );
    }

    public function test_the_canonical_code_of_a_referenced_practice_area_cannot_be_changed(): void
    {
        $practiceArea = PracticeArea::factory()->create(['code' => 'zzz_referenced', 'name' => 'Zzz Referenced']);
        MatterType::factory()->forPracticeArea($practiceArea)->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/canonical code of a referenced practice area cannot be changed/i');

        $this->service->update($practiceArea, ['code' => 'zzz_renamed']);
    }

    public function test_a_referenced_practice_area_can_still_have_its_name_and_description_edited(): void
    {
        $practiceArea = PracticeArea::factory()->create(['code' => 'zzz_referenced', 'name' => 'Zzz Referenced']);
        MatterType::factory()->forPracticeArea($practiceArea)->create();

        $updated = $this->service->update($practiceArea, [
            'name' => 'Zzz Referenced (renamed)',
            'description' => 'Still editable.',
        ]);

        $this->assertSame('Zzz Referenced (renamed)', $updated->name);
        $this->assertSame('zzz_referenced', $updated->code, 'the code must be untouched');
    }

    public function test_an_unreferenced_practice_area_may_still_have_its_code_corrected(): void
    {
        $practiceArea = PracticeArea::factory()->create(['code' => 'zzz_typoo', 'name' => 'Zzz Typo']);

        $updated = $this->service->update($practiceArea, ['code' => 'zzz_typo']);

        $this->assertSame('zzz_typo', $updated->code);
    }

    public function test_renaming_a_practice_area_onto_another_ones_identity_is_refused(): void
    {
        PracticeArea::factory()->create(['code' => 'zzz_target_area', 'name' => 'Zzz Target Area']);
        $editable = PracticeArea::factory()->create(['code' => 'zzz_other_area', 'name' => 'Zzz Other Area']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/may be a duplicate/i');

        $this->service->update($editable, ['name' => 'Zzz Target Area']);
    }

    public function test_a_practice_area_editing_its_own_unchanged_values_is_not_flagged_against_itself(): void
    {
        $practiceArea = PracticeArea::factory()->create(['code' => 'zzz_self_edit', 'name' => 'Zzz Self Edit']);

        $updated = $this->service->update($practiceArea, [
            'name' => 'Zzz Self Edit',
            'code' => 'zzz_self_edit',
            'description' => 'Updated description only.',
        ]);

        $this->assertSame('Updated description only.', $updated->description);
    }

    public function test_deactivation_is_a_soft_flip_that_never_deletes_the_row(): void
    {
        $practiceArea = PracticeArea::factory()->create(['code' => 'zzz_deact', 'is_active' => true]);
        MatterType::factory()->forPracticeArea($practiceArea)->create();

        $this->service->deactivate($practiceArea);

        $this->assertDatabaseHas('practice_areas', ['id' => $practiceArea->id, 'is_active' => false]);
    }
}
