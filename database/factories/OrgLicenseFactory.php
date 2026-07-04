<?php

namespace Database\Factories;

use App\Enums\LicenseStatus;
use App\Models\Organization;
use App\Models\OrgLicense;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrgLicense>
 */
class OrgLicenseFactory extends Factory
{
    protected $model = OrgLicense::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'plan_id' => Plan::factory(),
            'billing_account_id' => null,
            'license_key' => (string) Str::uuid(),
            'license_status' => LicenseStatus::Active,
            'starts_at' => now(),
            'renews_at' => now()->addYear(),
            'expires_at' => null,
            'cancelled_at' => null,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn () => ['organization_id' => $organization->id]);
    }

    public function status(LicenseStatus $status): static
    {
        return $this->state(fn () => ['license_status' => $status]);
    }
}
