<?php

namespace App\Models;

use App\Enums\ExportFileStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ExportFile — belongs to export_jobs; no firm_id of its own (scoped
 * transitively). simulated_storage_path is metadata only — no real
 * ZIP file is ever written (forbidden item).
 */
class ExportFile extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'export_job_id',
        'file_label',
        'simulated_storage_path',
        'size_bytes_estimate',
        'status',
        'generated_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExportFileStatus::class,
            'generated_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function exportJob(): BelongsTo
    {
        return $this->belongsTo(ExportJob::class);
    }
}
