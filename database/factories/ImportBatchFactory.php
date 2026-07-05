<?php

namespace Database\Factories;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportEntityType;
use App\Enums\ImportSourceType;
use App\Models\Firm;
use App\Models\ImportBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportBatch>
 */
class ImportBatchFactory extends Factory
{
    protected $model = ImportBatch::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'entity_type' => ImportEntityType::Client->value,
            'source_type' => ImportSourceType::CsvUpload->value,
            'status' => ImportBatchStatus::Draft->value,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function entityType(ImportEntityType $type): static
    {
        return $this->state(fn () => ['entity_type' => $type->value]);
    }
}
