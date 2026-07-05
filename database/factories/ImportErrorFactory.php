<?php

namespace Database\Factories;

use App\Enums\ImportErrorSeverity;
use App\Models\ImportError;
use App\Models\ImportRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportError>
 */
class ImportErrorFactory extends Factory
{
    protected $model = ImportError::class;

    public function definition(): array
    {
        return [
            'import_row_id' => ImportRow::factory(),
            'severity' => ImportErrorSeverity::Error->value,
            'message' => 'Sample validation error.',
        ];
    }
}
