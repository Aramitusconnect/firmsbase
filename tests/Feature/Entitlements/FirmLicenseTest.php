<?php

namespace Tests\Feature\Entitlements;

use App\Enums\LicenseStatus;
use App\Models\FirmLicense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FirmLicenseTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_via_factory(): void
    {
        $license = FirmLicense::factory()->create();

        $this->assertDatabaseHas('firm_licenses', ['id' => $license->id]);
    }

    public function test_no_plan_id_org_license_id_or_billing_mode_columns_exist(): void
    {
        $license = FirmLicense::factory()->create();
        $attributes = $license->getAttributes();

        $this->assertArrayNotHasKey('plan_id', $attributes);
        $this->assertArrayNotHasKey('org_license_id', $attributes);
        $this->assertArrayNotHasKey('billing_mode', $attributes);
    }

    public function test_license_status_casts_correctly(): void
    {
        $license = FirmLicense::factory()->status(LicenseStatus::Active)->create();

        $this->assertSame(LicenseStatus::Active, $license->fresh()->license_status);
    }

    public function test_all_twelve_status_values_are_available(): void
    {
        $this->assertCount(12, LicenseStatus::cases());
    }
}
