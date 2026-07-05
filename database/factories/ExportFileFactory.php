<?php

namespace Database\Factories;

use App\Enums\ExportFileStatus;
use App\Models\ExportFile;
use App\Models\ExportJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExportFile>
 */
class ExportFileFactory extends Factory
{
    protected $model = ExportFile::class;

    public function definition(): array
    {
        return [
            'export_job_id' => ExportJob::factory(),
            'file_label' => 'export-package',
            'simulated_storage_path' => 'exports/sample/package.zip',
            'status' => ExportFileStatus::Pending->value,
        ];
    }

    public function forJob(ExportJob $job): static
    {
        return $this->state(fn () => ['export_job_id' => $job->id]);
    }
}
