<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\ExpenseCategory;
use App\Models\Firm;
use App\Models\LeadSource;
use App\Services\FirmDefaultReferenceDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FirmDefaultReferenceDataServiceTest — FirmsVault staging follow-up
 * ("Application Completion — Catalogs + Firm-Owned Reference Data").
 * Proves the default-seeding is idempotent (safe to call twice, never
 * duplicates) and never overwrites a firm's own pre-existing custom
 * category/source that happens to share a default's name/code.
 */
final class FirmDefaultReferenceDataServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_all_fifteen_default_expense_categories(): void
    {
        $firm = Firm::factory()->create();

        $inserted = app(FirmDefaultReferenceDataService::class)->seedDefaultExpenseCategories($firm);

        $this->assertCount(15, $inserted);
        $count = $this->runWithFirmContext($firm, fn () => ExpenseCategory::query()->where('firm_id', $firm->id)->count());
        $this->assertSame(15, $count);
    }

    public function test_seeds_all_twelve_default_lead_sources(): void
    {
        $firm = Firm::factory()->create();

        $inserted = app(FirmDefaultReferenceDataService::class)->seedDefaultLeadSources($firm);

        $this->assertCount(12, $inserted);
        $count = $this->runWithFirmContext($firm, fn () => LeadSource::query()->where('firm_id', $firm->id)->count());
        $this->assertSame(12, $count);
    }

    public function test_calling_seed_twice_never_duplicates_rows(): void
    {
        $firm = Firm::factory()->create();
        $service = app(FirmDefaultReferenceDataService::class);

        $service->seedAllDefaults($firm);
        $secondRun = $service->seedAllDefaults($firm);

        $this->assertSame([], $secondRun['expense_categories']);
        $this->assertSame([], $secondRun['lead_sources']);

        $categoryCount = $this->runWithFirmContext($firm, fn () => ExpenseCategory::query()->where('firm_id', $firm->id)->count());
        $sourceCount = $this->runWithFirmContext($firm, fn () => LeadSource::query()->where('firm_id', $firm->id)->count());
        $this->assertSame(15, $categoryCount);
        $this->assertSame(12, $sourceCount);
    }

    public function test_never_overwrites_a_firms_own_pre_existing_custom_category_of_the_same_name(): void
    {
        $firm = Firm::factory()->create();
        $custom = $this->runWithFirmContext($firm, fn () => ExpenseCategory::factory()->forFirm($firm)->create([
            'name' => 'Filing Fees',
            'chart_of_accounts_id' => null,
            'is_active' => false,
        ]));

        app(FirmDefaultReferenceDataService::class)->seedDefaultExpenseCategories($firm);

        $fresh = $this->runWithFirmContext($firm, fn () => ExpenseCategory::query()->find($custom->id));
        $this->assertFalse($fresh->is_active, 'A pre-existing custom row with a matching name must never be mutated by the default seeder.');

        $count = $this->runWithFirmContext($firm, fn () => ExpenseCategory::query()->where('firm_id', $firm->id)->where('name', 'Filing Fees')->count());
        $this->assertSame(1, $count, 'The default seeder must skip inserting a name that already exists, not create a duplicate.');
    }

    public function test_never_overwrites_a_firms_own_pre_existing_custom_lead_source_of_the_same_code(): void
    {
        $firm = Firm::factory()->create();
        $custom = $this->runWithFirmContext($firm, fn () => LeadSource::factory()->forFirm($firm)->create([
            'code' => 'website',
            'name' => 'Custom Website Label',
            'is_active' => false,
        ]));

        app(FirmDefaultReferenceDataService::class)->seedDefaultLeadSources($firm);

        $fresh = $this->runWithFirmContext($firm, fn () => LeadSource::query()->find($custom->id));
        $this->assertSame('Custom Website Label', $fresh->name);
        $this->assertFalse($fresh->is_active);

        $count = $this->runWithFirmContext($firm, fn () => LeadSource::query()->where('firm_id', $firm->id)->where('code', 'website')->count());
        $this->assertSame(1, $count);
    }
}
