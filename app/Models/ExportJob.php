<?php

namespace App\Models;

use App\Enums\ExportJobStatus;
use App\Enums\ExportType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ExportJob — firm_id is non-nullable, so this model DOES use
 * BelongsToTenant (approved correction #10).
 */
class ExportJob extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'export_type',
        'status',
        'requested_by_firm_user_id',
        'requested_by_platform_admin_id',
        'reason',
        'legal_hold_checked',
        'retention_checked',
        'offboarding_checked',
        'started_at',
        'completed_at',
        'failed_reason',
    ];

    protected function casts(): array
    {
        return [
            'export_type' => ExportType::class,
            'status' => ExportJobStatus::class,
            'legal_hold_checked' => 'boolean',
            'retention_checked' => 'boolean',
            'offboarding_checked' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function requestedByFirmUser(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'requested_by_firm_user_id');
    }

    public function requestedByPlatformAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'requested_by_platform_admin_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ExportFile::class);
    }
}
