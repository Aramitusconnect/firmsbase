<?php

namespace Database\Factories;

use App\Enums\ImportRowStatus;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportRow>
 */
class ImportRowFactory extends Factory
{
    protected $model = ImportRow::class;

    public function definition(): array
    {
        return [
            'import_batch_id' => ImportBatch::factory(),
            'row_number' => 1,
            'raw_data' => ['name' => $this->faker->name(), 'email' => $this->faker->safeEmail()],
            'status' => ImportRowStatus::Staged->value,
        ];
    }

    public function forBatch(ImportBatch $batch): static
    {
        return $this->state(fn () => ['import_batch_id' => $batch->id]);
    }

    public function rawData(array $data): static
    {
        return $this->state(fn () => ['raw_data' => $data]);
    }
}
