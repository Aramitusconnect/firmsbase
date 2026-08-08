<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Enums\CustomerType;
use App\Enums\DeploymentMode;
use App\Enums\LicenseStatus;
use App\Models\Firm;
use App\Models\FirmLicense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PurchasedSeatsMigrationTest — verifies
 * 2026_08_08_100010_add_purchased_seats_to_firm_licenses_table is
 * additive only: one new nullable integer column on the EXISTING
 * firm_licenses table, no existing column touched, no existing row's
 * data altered, no NOT NULL constraint that could break a pre-existing
 * row that never populates it. Mirrors
 * FirmAddressPhoneMigrationTest's own style.
 */
class PurchasedSeatsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_new_column_exists_and_is_nullable(): void
    {
        $rows = DB::select(
            "SELECT column_name, is_nullable, data_type FROM information_schema.columns WHERE table_name = 'firm_licenses' AND column_name = 'purchased_seats'",
        );

        $this->assertCount(1, $rows, 'Expected exactly 1 new column on firm_licenses.');
        $this->assertSame('YES', $rows[0]->is_nullable, 'purchased_seats must be nullable.');
        $this->assertSame('integer', $rows[0]->data_type, 'purchased_seats must be an integer column.');
    }

    public function test_an_existing_style_license_row_created_without_the_new_column_is_unaffected(): void
    {
        $firm = Firm::factory()->create();

        // Simulates a pre-migration row: created with no knowledge of
        // purchased_seats at all, exactly like every firm_licenses row
        // that existed before this migration ran.
        $license = $this->runWithFirmContext($firm, fn () => FirmLicense::create([
            'firm_id' => $firm->id,
            'license_key' => (string) Str::uuid(),
            'license_status' => LicenseStatus::Trial,
            'deployment_mode' => DeploymentMode::Saas,
            'customer_type' => CustomerType::LawFirm,
            'starts_at' => now(),
        ]));

        $fresh = $this->runWithFirmContext($firm, fn () => FirmLicense::query()->find($license->id));

        $this->assertSame(LicenseStatus::Trial, $fresh->license_status, 'Existing columns must be completely unaffected by this migration.');
        $this->assertNull($fresh->purchased_seats, 'New column must default to null and never force a value onto an unrelated row.');
    }

    public function test_no_pre_existing_firm_licenses_columns_were_renamed_or_removed(): void
    {
        $columns = DB::select("SELECT column_name FROM information_schema.columns WHERE table_name = 'firm_licenses'");
        $names = collect($columns)->pluck('column_name')->all();

        foreach ([
            'id', 'uuid', 'firm_id', 'billing_account_id', 'org_license_id', 'plan_id',
            'license_key', 'license_status', 'deployment_mode', 'customer_type', 'billing_mode',
            'starts_at', 'renews_at', 'expires_at', 'cancelled_at', 'created_at', 'updated_at',
        ] as $preExistingColumn) {
            $this->assertContains($preExistingColumn, $names, "Pre-existing column '{$preExistingColumn}' must still exist unchanged.");
        }
    }
}
