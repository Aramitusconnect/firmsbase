<?php

namespace Database\Factories;

use App\Enums\DataProcessingRecordStatus;
use App\Enums\DataProcessingRecordType;
use App\Models\DataProcessingRecord;
use App\Models\PlatformAdmin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataProcessingRecord>
 */
class DataProcessingRecordFactory extends Factory
{
    protected $model = DataProcessingRecord::class;

    public function definition(): array
    {
        return [
            'record_type' => DataProcessingRecordType::DocumentStorage,
            'purpose' => 'Storing client-submitted legal documents for active matters.',
            'data_categories_json' => ['legal_documents', 'pii'],
            'legal_basis' => null,
            'vendor_register_id' => null,
            'subprocessor_id' => null,
            'retention_policy_id' => null,
            'firm_id' => null,
            'status' => DataProcessingRecordStatus::Active,
            'recorded_by_platform_admin_id' => PlatformAdmin::factory(),
            'recorded_at' => now(),
        ];
    }
}
