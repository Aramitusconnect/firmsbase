<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\ExpenseCategory;
use App\Models\Firm;
use App\Models\LeadSource;
use App\Services\FirmDefaultReferenceDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * InitializeDefaultFirmReferenceDataCommandTest — FirmsVault staging
 * follow-up ("Application Completion — Catalogs + Firm-Owned Reference
 * Data"). Proves: dry-run reports without mutating anything; apply mode
 * requires --firm and seeds exactly that firm; never duplicates
 * existing records; never overwrites a firm's own custom categories;
 * idempotent re-run.
 */
final class InitializeDefaultFirmReferenceDataCommandTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------
    // Dry-run / report mode
    // ------------------------------------------------------------

    public function test_dry_run_reports_a_firm_with_no_reference_data_without_mutating_anything(): void
    {
        $firm = Firm::factory()->create();

        $exitCode = Artisan::call('firms:initialize-default-reference-data');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString($firm->name, $output);

        $count = $this->runWithFirmContext($firm, fn () => ExpenseCategory::query()->where('firm_id', $firm->id)->count());
        $this->assertSame(0, $count, 'Dry-run must never mutate any row.');
    }

    public function test_dry_run_does_not_report_a_firm_that_already_has_all_defaults(): void
    {
        $firm = Firm::factory()->create();
        app(FirmDefaultReferenceDataService::class)->seedAllDefaults($firm);

        $this->artisan('firms:initialize-default-reference-data')
            ->assertExitCode(0)
            ->expectsOutputToContain('No firm is missing default reference data');
    }

    public function test_dry_run_can_be_scoped_to_a_single_firm(): void
    {
        $firmA = Firm::factory()->create(['name' => 'Scoped Firm']);
        $firmB = Firm::factory()->create(['name' => 'Other Firm']);

        $exitCode = Artisan::call('firms:initialize-default-reference-data', ['--firm' => $firmA->id]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Scoped Firm', $output);
        $this->assertStringNotContainsString('Other Firm', $output);
    }

    // ------------------------------------------------------------
    // Apply mode
    // ------------------------------------------------------------

    public function test_apply_requires_firm(): void
    {
        Firm::factory()->create();

        $this->artisan('firms:initialize-default-reference-data', ['--apply' => true])
            ->assertExitCode(1);
    }

    public function test_apply_seeds_exactly_the_supplied_firm(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->artisan('firms:initialize-default-reference-data', [
            '--apply' => true,
            '--firm' => $firmA->id,
        ])->assertExitCode(0);

        $countA = $this->runWithFirmContext($firmA, fn () => ExpenseCategory::query()->where('firm_id', $firmA->id)->count());
        $countB = $this->runWithFirmContext($firmB, fn () => ExpenseCategory::query()->where('firm_id', $firmB->id)->count());

        $this->assertSame(15, $countA);
        $this->assertSame(0, $countB, "A different firm's data must be completely unaffected.");
    }

    public function test_apply_fails_for_an_unknown_firm_id(): void
    {
        $this->artisan('firms:initialize-default-reference-data', [
            '--apply' => true,
            '--firm' => 999999,
        ])->assertExitCode(1);
    }

    public function test_apply_never_duplicates_existing_records(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => ExpenseCategory::factory()->forFirm($firm)->create(['name' => 'Filing Fees']));

        $this->artisan('firms:initialize-default-reference-data', [
            '--apply' => true,
            '--firm' => $firm->id,
        ])->assertExitCode(0);

        $count = $this->runWithFirmContext($firm, fn () => ExpenseCategory::query()->where('firm_id', $firm->id)->where('name', 'Filing Fees')->count());
        $this->assertSame(1, $count);
    }

    public function test_apply_never_overwrites_a_firms_own_custom_category(): void
    {
        $firm = Firm::factory()->create();
        $custom = $this->runWithFirmContext($firm, fn () => ExpenseCategory::factory()->forFirm($firm)->create([
            'name' => 'Filing Fees',
            'is_active' => false,
        ]));

        $this->artisan('firms:initialize-default-reference-data', [
            '--apply' => true,
            '--firm' => $firm->id,
        ])->assertExitCode(0);

        $fresh = $this->runWithFirmContext($firm, fn () => ExpenseCategory::query()->find($custom->id));
        $this->assertFalse($fresh->is_active, "The firm's own pre-existing row must never be mutated.");
    }

    public function test_apply_is_idempotent_when_re_run(): void
    {
        $firm = Firm::factory()->create();

        $this->artisan('firms:initialize-default-reference-data', ['--apply' => true, '--firm' => $firm->id])->assertExitCode(0);
        $this->artisan('firms:initialize-default-reference-data', ['--apply' => true, '--firm' => $firm->id])->assertExitCode(0);

        $categoryCount = $this->runWithFirmContext($firm, fn () => ExpenseCategory::query()->where('firm_id', $firm->id)->count());
        $sourceCount = $this->runWithFirmContext($firm, fn () => LeadSource::query()->where('firm_id', $firm->id)->count());

        $this->assertSame(15, $categoryCount);
        $this->assertSame(12, $sourceCount);
    }
}
