<?php

namespace App\Services;

use App\Enums\ExportFileStatus;
use App\Enums\ExportJobStatus;
use App\Models\ExportFile;
use App\Models\ExportJob;

/**
 * ExportPackageService — export package creation is SIMULATED through
 * metadata records only (project rule 4). No real ZIP file is ever
 * written to disk and no real external storage movement occurs
 * (forbidden items). simulated_storage_path is a descriptive metadata
 * string only — e.g. "exports/{firm_uuid}/{export_job_uuid}/package.zip"
 * — nothing is ever written at that path.
 */
class ExportPackageService
{
    public function generate(ExportJob $job, string $fileLabel, ?int $estimatedSizeBytes = null): ExportFile
    {
        if ($job->status !== ExportJobStatus::Requested && $job->status !== ExportJobStatus::InProgress) {
            throw new \InvalidArgumentException('Export package can only be generated for a requested or in-progress export job.');
        }

        $simulatedPath = sprintf('exports/%s/%s/%s', $job->firm->uuid, $job->uuid, \Illuminate\Support\Str::slug($fileLabel).'.zip');

        return $job->files()->create([
            'file_label' => $fileLabel,
            'simulated_storage_path' => $simulatedPath,
            'size_bytes_estimate' => $estimatedSizeBytes,
            'status' => ExportFileStatus::Generated,
            'generated_at' => now(),
        ]);
    }

    public function expire(ExportFile $file): ExportFile
    {
        $file->update(['status' => ExportFileStatus::Expired]);

        return $file->fresh();
    }
}
