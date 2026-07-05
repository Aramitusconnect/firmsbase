<?php

namespace Database\Factories;

use App\Enums\RollbackRecordStatus;
use App\Models\ImportBatch;
use App\Models\ImportRollbackRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportRollbackRecord>
 */
class ImportRollbackRecordFactory extends Factory
{
    protected $model = ImportRollbackRecord::class;

    public function definition(): array
    {
        return [
            'import_batch_id' => ImportBatch::factory(),
            'status' => RollbackRecordStatus::Pending->value,
        ];
    }
}
