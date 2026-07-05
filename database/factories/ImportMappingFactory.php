<?php

namespace Database\Factories;

use App\Models\ImportBatch;
use App\Models\ImportMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportMapping>
 */
class ImportMappingFactory extends Factory
{
    protected $model = ImportMapping::class;

    public function definition(): array
    {
        return [
            'import_batch_id' => ImportBatch::factory(),
            'source_field' => 'Email',
            'target_field' => 'email',
            'is_required' => false,
        ];
    }

    public function forBatch(ImportBatch $batch): static
    {
        return $this->state(fn () => ['import_batch_id' => $batch->id]);
    }
}
