<?php

namespace App\Models;

use App\Enums\OffboardingExportStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OffboardingExport — no firm_id of its own (scoped transitively via
 * offboarding_request_id/deletion_request_id), matching ExportFile's
 * own no-firm_id convention. Mutable (status advances Pending ->
 * Generated -> Verified/Expired) but deletion is always blocked once
 * created — this is governance evidence that a required export
 * happened, and destroying that record would undermine the very audit
 * trail Phase 17 exists to protect.
 */
class OffboardingExport extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'offboarding_request_id',
        'deletion_request_id',
        'export_job_id',
        'status',
        'package_manifest_json',
        'generated_at',
        'verified_at',
        'verified_by_platform_admin_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OffboardingExportStatus::class,
            'package_manifest_json' => 'array',
            'generated_at' => 'datetime',
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new \LogicException(
                'offboarding_exports rows can never be deleted — they are the governance evidence that a required export occurred.'
            );
        });
    }

    public function offboardingRequest(): BelongsTo
    {
        return $this->belongsTo(OffboardingRequest::class);
    }

    public function exportJob(): BelongsTo
    {
        return $this->belongsTo(ExportJob::class);
    }

    public function verifiedByPlatformAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'verified_by_platform_admin_id');
    }

    public function isVerified(): bool
    {
        return $this->status === OffboardingExportStatus::Verified;
    }
}
