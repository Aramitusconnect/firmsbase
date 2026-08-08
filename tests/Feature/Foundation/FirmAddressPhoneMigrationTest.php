<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * FirmAddressPhoneMigrationTest — verifies
 * 2026_08_07_100001_add_address_and_phone_to_firms_table is additive
 * only: five NEW nullable columns on the EXISTING firms table, no
 * existing column touched, no existing row's data altered, and no
 * NOT NULL constraint that could break a pre-existing row that never
 * populates them.
 */
class FirmAddressPhoneMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_COLUMNS = ['address_line1', 'address_line2', 'city', 'postal_code', 'phone_number'];

    public function test_all_five_new_columns_exist_and_are_nullable(): void
    {
        $placeholders = implode(',', array_fill(0, count(self::NEW_COLUMNS), '?'));

        $rows = DB::select(
            "SELECT column_name, is_nullable, data_type FROM information_schema.columns WHERE table_name = 'firms' AND column_name IN ({$placeholders})",
            self::NEW_COLUMNS,
        );

        $byName = collect($rows)->keyBy('column_name');

        $this->assertCount(5, $rows, 'Expected exactly 5 new columns on firms.');

        foreach (self::NEW_COLUMNS as $column) {
            $this->assertTrue($byName->has($column), "Expected column '{$column}' to exist on firms.");
            $this->assertSame('YES', $byName->get($column)->is_nullable, "Expected column '{$column}' to be nullable.");
            $this->assertSame('character varying', $byName->get($column)->data_type, "Expected column '{$column}' to be a string column.");
        }
    }

    public function test_an_existing_style_firm_row_created_without_the_new_columns_is_unaffected(): void
    {
        // Simulates a pre-migration row: created with no knowledge of
        // the new columns at all, exactly like every firm row that
        // existed before this migration ran.
        $firm = Firm::factory()->create([
            'legal_name' => 'Pre-existing Firm LLC',
            'primary_country' => 'US',
        ]);

        $fresh = Firm::query()->find($firm->id);

        $this->assertSame('Pre-existing Firm LLC', $fresh->legal_name, 'Existing columns must be completely unaffected by this migration.');
        $this->assertSame('US', $fresh->primary_country);

        foreach (self::NEW_COLUMNS as $column) {
            $this->assertNull($fresh->{$column}, "New column '{$column}' must default to null and never force a value onto an unrelated row.");
        }
    }

    public function test_no_pre_existing_firms_columns_were_renamed_or_removed(): void
    {
        $columns = DB::select("SELECT column_name FROM information_schema.columns WHERE table_name = 'firms'");
        $names = collect($columns)->pluck('column_name')->all();

        foreach ([
            'id', 'uuid', 'organization_id', 'billing_account_id', 'name', 'legal_name',
            'customer_type', 'deployment_mode', 'primary_country', 'primary_state',
            'default_timezone', 'default_currency', 'data_region', 'activation_status',
            'created_at', 'updated_at',
        ] as $preExistingColumn) {
            $this->assertContains($preExistingColumn, $names, "Pre-existing column '{$preExistingColumn}' must still exist unchanged.");
        }
    }
}
