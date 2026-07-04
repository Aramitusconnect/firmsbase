<?php

namespace Database\Factories;

use App\Enums\LicenseStatus;
use App\Models\Firm;
use App\Models\FirmLicense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FirmLicense>
 */
class FirmLicenseFactory extends Factory
{
    protected $model = FirmLicense::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'billing_account_id' => null,
            'license_key' => strtoupper($this->faker->bothify('LIC-????-####')),
            'license_status' => LicenseStatus::Trial,
            'starts_at' => now(),
            'renews_at' => null,
            'expires_at' => now()->addDays(14),
            'cancelled_at' => null,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function status(LicenseStatus $status): static
    {
        return $this->state(fn () => ['license_status' => $status]);
    }
}
