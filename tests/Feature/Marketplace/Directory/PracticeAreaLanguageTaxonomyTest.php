<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Directory;

use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryFirm;
use App\Models\Language;
use App\Models\PracticeArea;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PracticeAreaLanguageTaxonomyTest — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 2. Proves the controlled taxonomy is genuinely
 * shared (not duplicated) between the pre-existing internal
 * FirmPracticeArea usage and the new marketplace pivots (section 12),
 * that the seeded catalog exists and is marketplace-visible, and that
 * Firm/Attorney <-> PracticeArea/Language associations work in both
 * directions.
 */
class PracticeAreaLanguageTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PIVOT_TABLES = [
        'languages', 'directory_firm_practice_areas', 'directory_attorney_practice_areas',
        'directory_firm_languages', 'directory_attorney_languages',
    ];

    public function test_the_marketplace_practice_area_catalog_was_seeded_and_is_marketplace_visible(): void
    {
        $this->assertSame(16, PracticeArea::query()->where('is_marketplace_visible', true)->count());

        $personalInjury = PracticeArea::query()->where('code', 'personal-injury')->first();

        $this->assertNotNull($personalInjury);
        $this->assertTrue($personalInjury->is_marketplace_visible);
        $this->assertTrue($personalInjury->is_active);
        $this->assertSame('personal-injury', $personalInjury->slug);
    }

    public function test_a_practice_area_can_be_marketplace_visible_independent_of_internal_active_status(): void
    {
        $category = PracticeArea::factory()->create(['is_active' => true, 'is_marketplace_visible' => false]);

        $this->assertTrue($category->is_active);
        $this->assertFalse($category->is_marketplace_visible);
    }

    public function test_directory_firm_practice_area_association_is_bidirectional(): void
    {
        $firm = DirectoryFirm::factory()->create();
        $category = PracticeArea::query()->where('code', 'family-law')->firstOrFail();

        $firm->practiceAreas()->attach($category->id, ['source_type' => 'admin_entered']);

        $this->assertTrue($firm->practiceAreas->contains($category));
        $this->assertTrue($category->directoryFirms->contains($firm));
    }

    public function test_directory_attorney_practice_area_association_is_bidirectional(): void
    {
        $attorney = DirectoryAttorney::factory()->create();
        $category = PracticeArea::query()->where('code', 'immigration')->firstOrFail();

        $attorney->practiceAreas()->attach($category->id, ['source_type' => 'admin_entered']);

        $this->assertTrue($attorney->practiceAreas->contains($category));
        $this->assertTrue($category->directoryAttorneys->contains($attorney));
    }

    public function test_directory_firm_language_association_is_bidirectional(): void
    {
        $firm = DirectoryFirm::factory()->create();
        $spanish = Language::factory()->spanish()->create();

        $firm->languages()->attach($spanish->id, ['source_type' => 'admin_entered']);

        $this->assertTrue($firm->languages->contains($spanish));
        $this->assertTrue($spanish->directoryFirms->contains($firm));
    }

    public function test_directory_attorney_language_association_is_bidirectional(): void
    {
        $attorney = DirectoryAttorney::factory()->create();
        $arabic = Language::factory()->arabic()->create();

        $attorney->languages()->attach($arabic->id, ['source_type' => 'admin_entered']);

        $this->assertTrue($attorney->languages->contains($arabic));
        $this->assertTrue($arabic->directoryAttorneys->contains($attorney));
    }

    public function test_duplicate_firm_practice_area_association_is_rejected(): void
    {
        $firm = DirectoryFirm::factory()->create();
        $category = PracticeArea::factory()->create();

        $firm->practiceAreas()->attach($category->id, ['source_type' => 'admin_entered']);

        $this->expectException(QueryException::class);
        DB::table('directory_firm_practice_areas')->insert([
            'directory_firm_id' => $firm->id,
            'practice_area_id' => $category->id,
            'source_type' => 'admin_entered',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_every_new_taxonomy_table_is_genuinely_exempt_from_row_level_security(): void
    {
        foreach (self::NEW_PIVOT_TABLES as $table) {
            $row = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);

            $this->assertNotNull($row, "Table {$table} not found in pg_class.");
            $this->assertFalse((bool) $row->relrowsecurity, "RLS must NOT be enabled on {$table} — it is platform-global data.");
            $this->assertFalse((bool) $row->relforcerowsecurity, "FORCE RLS must NOT be enabled on {$table}.");
        }
    }
}
