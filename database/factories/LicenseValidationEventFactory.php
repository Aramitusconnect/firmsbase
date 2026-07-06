<?php

namespace Database\Factories;

use App\Enums\LicenseValidationEventType;
use App\Enums\LicenseValidationResult;
use App\Models\LicenseFile;
use App\Models\LicenseValidationEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LicenseValidationEvent>
 */
class LicenseValidationEventFactory extends Factory
{
    protected $model = LicenseValidationEvent::class;

    public function definition(): array
    {
        return [
            'license_file_id' => LicenseFile::factory(),
            'firm_id' => null,
            'event_type' => LicenseValidationEventType::Validated,
            'result' => LicenseValidationResult::Valid,
            'validated_at' => now(),
        ];
    }

    public function forLicenseFile(LicenseFile $licenseFile): static
    {
        return $this->state(fn () => [
            'license_file_id' => $licenseFile->id,
            'firm_id' => $licenseFile->firm_id,
        ]);
    }
}
