<?php

namespace Database\Factories;

use App\Enums\SubprocessorStatus;
use App\Models\Subprocessor;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subprocessor>
 */
class SubprocessorFactory extends Factory
{
    protected $model = Subprocessor::class;

    public function definition(): array
    {
        return [
            'vendor_register_id' => Vendor::factory(),
            'disclosed_name' => 'Example Cloud Storage Inc.',
            'service_purpose' => 'Document and file storage infrastructure.',
            'data_categories_json' => ['legal_documents'],
            'regions_json' => ['us'],
            'is_publicly_disclosed' => true,
            'disclosure_effective_at' => now(),
            'status' => SubprocessorStatus::Active,
        ];
    }

    public function forVendor(Vendor $vendor): static
    {
        return $this->state(fn () => ['vendor_register_id' => $vendor->id]);
    }
}
