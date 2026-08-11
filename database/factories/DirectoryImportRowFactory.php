<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Marketplace\Enums\DirectoryImportRowStatus;
use App\Marketplace\Models\DirectoryImportBatch;
use App\Marketplace\Models\DirectoryImportRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DirectoryImportRow>
 */
class DirectoryImportRowFactory extends Factory
{
    protected $model = DirectoryImportRow::class;

    public function definition(): array
    {
        return [
            'directory_import_batch_id' => DirectoryImportBatch::factory(),
            'row_number' => 1,
            'raw_data' => [
                'legal_name' => 'Acme Legal PLLC',
                'display_name' => 'Acme Legal',
                'phone' => '5555550100',
                'website' => 'https://acme-legal.example.com',
                'city' => 'Detroit',
                'state' => 'MI',
            ],
            'status' => DirectoryImportRowStatus::Pending,
        ];
    }

    public function forBatch(DirectoryImportBatch $batch): static
    {
        return $this->state(fn () => ['directory_import_batch_id' => $batch->id]);
    }

    public function valid(): static
    {
        return $this->state(fn () => [
            'status' => DirectoryImportRowStatus::Valid,
            'mapped_data' => [
                'legal_name' => 'Acme Legal PLLC',
                'display_name' => 'Acme Legal',
                'phone' => '5555550100',
                'website' => 'https://acme-legal.example.com',
                'city' => 'Detroit',
                'state' => 'MI',
            ],
        ]);
    }
}
